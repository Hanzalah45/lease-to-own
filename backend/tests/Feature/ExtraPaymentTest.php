<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\RiskRedFlag;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Notifications\EarlyPurchasePricePaidNotification;
use App\Services\LeaseEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;
use Stripe\WebhookSignature;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Extra payments toward the early purchase price (client, Joel, 2026-10-16: "how are they able to make extra payments
 * toward the principal when they want to?"; the signed plan and contract Section 19, "Additional Funds"). A customer pays
 * from their own screen or staff record one taken in the office; once paid it lowers the early purchase price.
 */
class ExtraPaymentTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
        $this->travelTo(Carbon::parse('2026-10-15 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /** A picked-up lease with both methods on file, cash price $3,000 and $200 a month: early purchase price $2,800 in month 1. */
    private function lease(array $overrides = [], string $status = Application::STATUS_FINISHED): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_extra']);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => $status]);

        $lease = LeaseAgreement::factory()->create(array_merge([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => null,
            'term_months' => 36,
            'cash_price' => 3000,
            'monthly_rental_payment' => 200,
            'sales_tax_rate' => 0,
            'additional_funds' => 0,
            'autopay_enabled' => false,
            'stripe_bank_payment_method_id' => 'pm_bank',
            'stripe_card_payment_method_id' => 'pm_card',
        ], $overrides));
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_RENTAL,
            'amount' => 200,
            'due_date' => '2026-10-01',
            'status' => Payment::STATUS_PAID,
            'paid_date' => '2026-10-01',
        ]);

        return $lease;
    }

    private function intent(string $id, string $status): array
    {
        return ['id' => $id, 'object' => 'payment_intent', 'status' => $status];
    }

    private function extra(User $customer, LeaseAgreement $lease, array $body = []): TestResponse
    {
        return $this->actingAs($customer, 'sanctum')->postJson("/api/customer/lease-agreements/{$lease->id}/extra-payment", array_merge([
            'amount' => 100,
            'method' => 'card',
        ], $body));
    }

    private function record(User $admin, LeaseAgreement $lease, array $body = []): TestResponse
    {
        return $this->actingAs($admin, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/extra-payment", array_merge([
            'amount' => 250,
            'method' => 'cash',
        ], $body));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    private function webhook(string $type, array $intent): TestResponse
    {
        config(['services.stripe.webhook_secret' => 'whsec_test_secret_for_specs_only']);
        $payload = json_encode([
            'id' => 'evt_'.Str::random(16),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $intent + ['object' => 'payment_intent']],
        ]);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => WebhookSignature::generateSignatureHeader($payload, 'whsec_test_secret_for_specs_only'),
        ], $payload);
    }

    public function test_the_options_say_how_much_can_be_paid_and_with_which_methods(): void
    {
        $lease = $this->lease();

        $this->actingAs($lease->customer, 'sanctum')->getJson("/api/customer/lease-agreements/{$lease->id}/extra-payment")
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.min', 5)
            ->assertJsonPath('data.max', 2800)
            ->assertJsonPath('data.epo_today', 2800)
            ->assertJsonPath('data.available_methods', ['bank', 'card'])
            ->assertJsonPath('data.card_fee_percent', 3);
    }

    public function test_a_card_extra_payment_is_charged_at_the_card_price_and_lowers_the_early_purchase_price(): void
    {
        $lease = $this->lease();
        $this->stripe->queue($this->intent('pi_extra_card', 'succeeded'));

        $this->extra($lease->customer, $lease, ['amount' => 100, 'method' => 'card', 'expected_total_cents' => 10300])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.epo_today', 2700);

        $payment = Payment::where('type', Payment::TYPE_EXTRA_PAYMENT)->first();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertEqualsWithDelta(100.0, (float) $payment->amount, 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $payment->card_fee_amount, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $lease->fresh()->additional_funds, 0.001);

        // One charge, for the card price, on the saved card, with the customer present (no off_session).
        $this->assertCount(1, $this->stripe->requests);
        $params = $this->stripe->requests[0]['params'];
        $this->assertSame(10300, (int) $params['amount']);
        $this->assertSame('pm_card', $params['payment_method']);
        $this->assertArrayNotHasKey('off_session', $params);
        $this->assertStringContainsString('Extra payment toward the early purchase price', $params['description']);
        $this->assertSame(Payment::TYPE_EXTRA_PAYMENT, $params['metadata']['type']);
    }

    public function test_a_bank_extra_payment_counts_only_once_the_bank_has_cleared_it(): void
    {
        $lease = $this->lease();
        $this->stripe->queue($this->intent('pi_extra_bank', 'processing'));

        $this->extra($lease->customer, $lease, ['amount' => 100, 'method' => 'bank'])
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.epo_today', 2800);

        $payment = Payment::where('type', Payment::TYPE_EXTRA_PAYMENT)->first();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame(10000, (int) $this->stripe->requests[0]['params']['amount']);
        $this->assertEqualsWithDelta(0.0, (float) $lease->fresh()->additional_funds, 0.001);

        // The bank clears it: the webhook settles it like any other payment and the money lands in the funds.
        $this->webhook('payment_intent.succeeded', $this->intent('pi_extra_bank', 'succeeded'))->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertEqualsWithDelta(100.0, (float) $lease->fresh()->additional_funds, 0.001);
        $this->assertEqualsWithDelta(2700.0, LeaseEngine::epoToday($lease->fresh()), 0.001);
        $this->assertSame(1, StripeWebhookEvent::count());
    }

    public function test_a_declined_extra_payment_raises_no_risk_flag_and_can_be_tried_again(): void
    {
        $lease = $this->lease();
        $this->stripe->queue(['error' => [
            'message' => 'Your card has insufficient funds.',
            'type' => 'card_error',
            'code' => 'card_declined',
            'decline_code' => 'insufficient_funds',
            'payment_intent' => $this->intent('pi_extra_declined', 'requires_payment_method'),
        ]], 402);

        $this->extra($lease->customer, $lease)
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.message', 'Your card has insufficient funds.');

        $this->assertSame(0, RiskRedFlag::count());
        $this->assertEqualsWithDelta(0.0, (float) $lease->fresh()->additional_funds, 0.001);

        // A failed extra payment is nothing owed: it does not block the next try and never counts as arrears.
        $this->stripe->queue($this->intent('pi_extra_second', 'succeeded'));
        $this->extra($lease->customer, $lease)->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertEqualsWithDelta(100.0, (float) $lease->fresh()->additional_funds, 0.001);
    }

    public function test_the_amount_must_be_within_the_range_and_the_whole_price_is_a_payoff(): void
    {
        $lease = $this->lease();

        $this->extra($lease->customer, $lease, ['amount' => 4.99])->assertStatus(422);
        $this->extra($lease->customer, $lease, ['amount' => 2800.01])->assertStatus(422);
        $this->assertCount(0, $this->stripe->requests);
        $this->assertSame(0, Payment::where('type', Payment::TYPE_EXTRA_PAYMENT)->count());

        // Exactly the early purchase price: nothing is left to pay.
        $this->stripe->queue($this->intent('pi_payoff', 'succeeded'));
        $this->extra($lease->customer, $lease, ['amount' => 2800, 'method' => 'bank'])->assertOk()->assertJsonPath('data.epo_today', 0);
        $this->assertEqualsWithDelta(0.0, LeaseEngine::epoToday($lease->fresh()), 0.001);
    }

    public function test_paying_the_whole_price_pauses_autopay_and_tells_staff_what_is_left_to_do(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $lease = $this->lease(['autopay_enabled' => true]);

        $this->record($admin, $lease, ['amount' => 2800])->assertOk()->assertJsonPath('data.epo_today', 0);

        $this->assertNotNull($lease->fresh()->autopay_paused_at);
        Notification::assertSentTo($admin, EarlyPurchasePricePaidNotification::class);

        // Nothing is left to pay toward the price.
        $this->extra($lease->customer, $lease)->assertStatus(422);

        // A smaller extra payment does not pause anything.
        $other = $this->lease(['autopay_enabled' => true]);
        $this->record($admin, $other, ['amount' => 100])->assertOk();
        $this->assertNull($other->fresh()->autopay_paused_at);
    }

    public function test_a_changed_price_is_refused_without_charging_and_does_not_block_the_next_try(): void
    {
        $lease = $this->lease();

        $this->extra($lease->customer, $lease, ['amount' => 100, 'method' => 'card', 'expected_total_cents' => 10000])->assertStatus(409);

        $this->assertCount(0, $this->stripe->requests);
        $this->assertSame(0, Payment::where('type', Payment::TYPE_EXTRA_PAYMENT)->count());

        $this->stripe->queue($this->intent('pi_after_409', 'succeeded'));
        $this->extra($lease->customer, $lease, ['amount' => 100, 'method' => 'card', 'expected_total_cents' => 10300])->assertOk();
    }

    public function test_it_waits_until_what_is_due_has_been_paid(): void
    {
        $lease = $this->lease();
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_RENTAL,
            'amount' => 200,
            'due_date' => '2026-10-15',
            'status' => Payment::STATUS_PENDING,
        ]);

        $this->actingAs($lease->customer, 'sanctum')->getJson("/api/customer/lease-agreements/{$lease->id}/extra-payment")
            ->assertOk()
            ->assertJsonPath('data.available', false);
        $this->extra($lease->customer, $lease)->assertStatus(422);

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_it_is_not_offered_before_pickup_without_a_saved_method_or_to_another_customer(): void
    {
        $early = $this->lease([], Application::STATUS_WAITING_DEPOSIT);
        $this->extra($early->customer, $early)->assertStatus(422);

        $none = $this->lease(['stripe_bank_payment_method_id' => null, 'stripe_card_payment_method_id' => null]);
        $this->actingAs($none->customer, 'sanctum')->getJson("/api/customer/lease-agreements/{$none->id}/extra-payment")
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.blocker', 'Save a bank account or card first.');

        $other = $this->lease();
        $this->extra($early->customer, $other)->assertNotFound();

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_an_extra_payment_still_processing_blocks_a_second_one(): void
    {
        $lease = $this->lease();
        $this->stripe->queue($this->intent('pi_extra_slow', 'processing'));
        $this->extra($lease->customer, $lease, ['method' => 'bank'])->assertOk();

        $this->extra($lease->customer, $lease, ['method' => 'bank'])->assertStatus(422);

        $this->assertCount(1, $this->stripe->requests);
    }

    public function test_staff_record_an_extra_payment_taken_in_the_office(): void
    {
        $lease = $this->lease();
        $admin = $this->admin();

        $this->record($admin, $lease, ['amount' => 250, 'method' => 'check'])
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'paid')
            ->assertJsonPath('data.epo_today', 2550);

        $payment = Payment::where('type', Payment::TYPE_EXTRA_PAYMENT)->first();
        $this->assertSame('check', $payment->method);
        $this->assertSame($admin->id, $payment->recorded_by);
        $this->assertEqualsWithDelta(250.0, (float) $lease->fresh()->additional_funds, 0.001);

        $note = $lease->application->dealerNotes()->latest('id')->first();
        $this->assertStringContainsString('$250.00', $note->text);
        $this->assertStringContainsString($admin->name, $note->text);
        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_staff_cannot_record_more_than_the_price_or_while_something_is_due(): void
    {
        $lease = $this->lease();

        $this->record($this->admin(), $lease, ['amount' => 2800.01])->assertStatus(422);

        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_RENTAL,
            'amount' => 200,
            'due_date' => '2026-10-14',
            'status' => Payment::STATUS_PENDING,
        ]);
        $this->record($this->admin(), $lease, ['amount' => 100])->assertStatus(422);

        $this->assertSame(0, Payment::where('type', Payment::TYPE_EXTRA_PAYMENT)->count());
    }

    public function test_only_staff_with_payment_tracking_can_record_one(): void
    {
        $lease = $this->lease();

        $this->record($lease->customer, $lease)->assertForbidden();

        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);
        $this->record($reviewer, $lease)->assertForbidden();

        $this->assertSame(0, Payment::where('type', Payment::TYPE_EXTRA_PAYMENT)->count());
    }

    public function test_a_refunded_extra_payment_takes_its_money_off_the_funds_again(): void
    {
        $lease = $this->lease();
        $this->record($this->admin(), $lease, ['amount' => 300])->assertOk();
        $payment = Payment::where('type', Payment::TYPE_EXTRA_PAYMENT)->first();
        $this->assertEqualsWithDelta(300.0, (float) $lease->fresh()->additional_funds, 0.001);

        LeaseEngine::applyPaymentStatusChange($payment->fresh(), Payment::STATUS_REFUNDED, null, null);
        $this->assertEqualsWithDelta(0.0, (float) $lease->fresh()->additional_funds, 0.001);

        // Saving the same status again changes nothing.
        LeaseEngine::applyPaymentStatusChange($payment->fresh(), Payment::STATUS_REFUNDED, null, null);
        $this->assertEqualsWithDelta(0.0, (float) $lease->fresh()->additional_funds, 0.001);
    }

    public function test_additional_funds_lower_the_price_in_the_first_90_days_and_after_and_a_failed_one_is_not_arrears(): void
    {
        $lease = $this->lease(['additional_funds' => 100]);
        // Month 1 (within 90 days): 3000 - 200 - 100.
        $this->assertEqualsWithDelta(2700.0, LeaseEngine::epoToday($lease->fresh()), 0.001);

        // Month 4 and later: 3000 - 50% of (4 x 200) - 100 = 2500.
        foreach ([2, 3, 4] as $month) {
            Payment::factory()->create([
                'lease_agreement_id' => $lease->id,
                'type' => Payment::TYPE_RENTAL,
                'amount' => 200,
                'due_date' => "2026-0{$month}-01",
                'status' => Payment::STATUS_PAID,
                'paid_date' => "2026-0{$month}-01",
            ]);
        }
        $this->assertEqualsWithDelta(2500.0, LeaseEngine::epoToday($lease->fresh()), 0.001);

        // A declined extra payment is nothing owed, so it never raises the price.
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_EXTRA_PAYMENT,
            'amount' => 100,
            'due_date' => '2026-10-14',
            'status' => Payment::STATUS_FAILED,
        ]);
        $this->assertEqualsWithDelta(2500.0, LeaseEngine::epoToday($lease->fresh()), 0.001);
    }
}

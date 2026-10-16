<?php

namespace Tests\Feature;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\WebhookSignature;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * "Pay now" (client, Joel, 2026-10-15): a customer pays a due monthly payment
 * themselves. Real case: a customer with AutoPay switched off had a payment
 * due and no way to pay it from the portal.
 */
class CustomerPayNowTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
        // 10:00 in Texas on the 15th.
        $this->travelTo(Carbon::parse('2026-10-15 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /** A picked-up lease with AutoPay OFF and both methods on file. */
    private function lease(array $overrides = []): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_paynow']);

        return LeaseAgreement::factory()->pickedUp()->create(array_merge([
            'customer_id' => $customer->id,
            'monthly_rental_payment' => 200,
            'sales_tax_rate' => 0,
            'autopay_enabled' => false,
            'stripe_bank_payment_method_id' => 'pm_bank',
            'stripe_card_payment_method_id' => 'pm_card',
            'autopay_primary_method' => null,
        ], $overrides));
    }

    private function due(LeaseAgreement $lease, array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_RENTAL,
            'amount' => 200,
            'due_date' => '2026-10-15',
            'status' => Payment::STATUS_PENDING,
        ], $overrides));
    }

    private function intent(string $id, string $status, array $extra = []): array
    {
        return ['id' => $id, 'object' => 'payment_intent', 'status' => $status] + $extra;
    }

    private function pay(User $customer, Payment $payment, array $body = []): TestResponse
    {
        return $this->actingAs($customer, 'sanctum')->postJson("/api/customer/payments/{$payment->id}/pay", $body);
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

    public function test_the_payments_list_says_what_can_be_paid_and_at_which_price(): void
    {
        $lease = $this->lease();
        $due = $this->due($lease);
        $this->due($lease, ['due_date' => '2026-11-15']);
        $paid = $this->due($lease, ['due_date' => '2026-09-15', 'status' => Payment::STATUS_PAID]);

        $rows = collect($this->actingAs($lease->customer, 'sanctum')->getJson('/api/customer/payments')->assertOk()->json('data'))->keyBy('id');

        $pay = $rows[$due->id]['pay'];
        $this->assertTrue($pay['payable']);
        $this->assertSame(['bank', 'card'], $pay['available_methods']);
        $this->assertEquals(200.0, $pay['prices']['bank']);
        $this->assertEquals(206.0, $pay['prices']['card']);
        // A future payment and a paid one are not payable here.
        $this->assertNull($rows[$due->id + 1]['pay']);
        $this->assertNull($rows[$paid->id]['pay']);
    }

    public function test_a_card_payment_is_charged_at_the_card_price_and_marked_paid(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_now_card', 'succeeded'));

        $this->pay($lease->customer, $payment, ['method' => 'card', 'expected_total_cents' => 20600])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment.status', 'paid');

        $payment->refresh();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('card', $payment->method);
        $this->assertEqualsWithDelta(6.0, (float) $payment->card_fee_amount, 0.001);

        $request = collect($this->stripe->requests)->first(fn ($r) => str_contains($r['url'], 'payment_intents'));
        $this->assertSame(20600, (int) $request['params']['amount']);
        $this->assertSame('pm_card', $request['params']['payment_method']);
        // The customer is present: not an off-session charge, so a card can ask for extra confirmation on screen.
        $this->assertArrayNotHasKey('off_session', $request['params']);

        $attempt = $payment->attempts()->sole();
        $this->assertTrue($attempt->startedByCustomer());
        $this->assertSame(PaymentAttempt::STATUS_SUCCEEDED, $attempt->status);
        $this->assertSame(20600, $attempt->amount_cents);
    }

    public function test_a_bank_payment_is_left_processing_at_the_bank_price(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_now_bank', 'processing'));

        $this->pay($lease->customer, $payment, ['method' => 'bank', 'expected_total_cents' => 20000])
            ->assertOk()
            ->assertJsonPath('data.status', 'processing');

        $payment->refresh();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame('ach', $payment->method);
        $this->assertEqualsWithDelta(0.0, (float) $payment->card_fee_amount, 0.001);
        $this->assertSame('pi_now_bank', $payment->stripe_payment_intent_id);
        $this->assertSame(PaymentAttempt::STATUS_PROCESSING, $payment->attempts()->sole()->status);
    }

    public function test_the_bank_debit_is_settled_by_the_webhook_like_any_other(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_now_bank2', 'processing'));
        $this->pay($lease->customer, $payment, ['method' => 'bank'])->assertOk();

        $this->webhook('payment_intent.succeeded', $this->intent('pi_now_bank2', 'succeeded'))->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(PaymentAttempt::STATUS_SUCCEEDED, $payment->attempts()->sole()->status);
        $this->assertSame(1, StripeWebhookEvent::count());
    }

    public function test_a_changed_price_is_refused_without_charging(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);

        $this->pay($lease->customer, $payment, ['method' => 'card', 'expected_total_cents' => 20000])->assertStatus(409);

        $this->assertCount(0, $this->stripe->requests);
        $this->assertSame(0, $payment->attempts()->count());
    }

    public function test_a_payment_that_is_not_due_yet_or_is_not_a_monthly_payment_is_refused(): void
    {
        $lease = $this->lease();
        $future = $this->due($lease, ['due_date' => '2026-11-15']);
        $deposit = $this->due($lease, ['type' => Payment::TYPE_DEPOSIT]);

        $this->pay($lease->customer, $future)->assertStatus(422);
        $this->pay($lease->customer, $deposit)->assertStatus(422);

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_only_the_customers_own_payment_can_be_paid(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);
        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->pay($stranger, $payment)->assertNotFound();

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_lease_with_nothing_saved_cannot_be_paid(): void
    {
        $lease = $this->lease(['stripe_bank_payment_method_id' => null, 'stripe_card_payment_method_id' => null]);
        $payment = $this->due($lease);

        $this->pay($lease->customer, $payment)->assertStatus(422);

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_declined_card_fails_the_payment_with_a_reason_and_can_be_tried_again(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);
        $this->stripe->queue(['error' => [
            'message' => 'Your card was declined.',
            'type' => 'card_error',
            'code' => 'card_declined',
            'decline_code' => 'generic_decline',
            'payment_intent' => $this->intent('pi_declined_now', 'requires_payment_method'),
        ]], 402);

        $this->pay($lease->customer, $payment, ['method' => 'card'])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.message', 'Your card was declined.');

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        // The customer chose the card, so the bank was NOT charged behind their back.
        $this->assertSame(1, $payment->attempts()->count());

        // A failed payment can be paid again, as a new attempt with its own key.
        $this->stripe->queue($this->intent('pi_second_try', 'succeeded'));
        $this->pay($lease->customer, $payment, ['method' => 'card'])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $keys = $payment->attempts()->orderBy('id')->pluck('idempotency_key')->all();
        $this->assertCount(2, array_unique($keys));
    }

    /** Real case (2026-10-16): a customer whose card was declined twice got "Server Error" on every press after that. */
    public function test_a_payment_declined_several_times_can_still_be_paid_again(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);

        foreach (['pi_declined_1', 'pi_declined_2', 'pi_declined_3'] as $intentId) {
            $this->stripe->queue(['error' => [
                'message' => 'Your card has insufficient funds.',
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'insufficient_funds',
                'payment_intent' => $this->intent($intentId, 'requires_payment_method'),
            ]], 402);

            $this->pay($lease->customer, $payment, ['method' => 'card'])
                ->assertOk()
                ->assertJsonPath('data.status', 'failed')
                ->assertJsonPath('data.message', 'Your card has insufficient funds.');
        }

        $this->assertSame([1, 2, 3], $payment->attempts()->pluck('attempt_no')->all());

        // The fourth press, with a card that works, is a new attempt numbered after the last one.
        $this->stripe->queue($this->intent('pi_fourth_try', 'succeeded'));
        $this->pay($lease->customer, $payment, ['method' => 'card'])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame([1, 2, 3, 4], $payment->attempts()->pluck('attempt_no')->all());
        $this->assertCount(4, array_unique($payment->attempts()->pluck('idempotency_key')->all()));
    }

    public function test_a_payment_already_processing_at_the_bank_is_not_charged_again(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_in_flight', 'processing'));
        $this->pay($lease->customer, $payment, ['method' => 'bank'])->assertOk();
        $requestsBefore = count($this->stripe->requests);

        $this->pay($lease->customer, $payment, ['method' => 'card'])->assertStatus(422);

        $this->assertCount($requestsBefore, $this->stripe->requests);
        $this->assertSame(1, $payment->attempts()->count());
    }

    public function test_a_card_that_needs_extra_confirmation_returns_the_secret_and_the_webhook_finishes_it(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_3ds', 'requires_action', ['client_secret' => 'pi_3ds_secret_x']));

        $this->pay($lease->customer, $payment, ['method' => 'card'])
            ->assertOk()
            ->assertJsonPath('data.status', 'requires_action')
            ->assertJsonPath('data.client_secret', 'pi_3ds_secret_x');

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);

        $this->webhook('payment_intent.succeeded', $this->intent('pi_3ds', 'succeeded'))->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_an_abandoned_extra_confirmation_is_cleaned_up_by_the_next_press(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_abandoned', 'requires_action', ['client_secret' => 'pi_abandoned_secret_x']));
        $this->pay($lease->customer, $payment, ['method' => 'card'])->assertOk();

        // The customer closed the confirmation step. Stripe still shows it waiting; the next press cancels it and starts over.
        $this->stripe->queue($this->intent('pi_abandoned', 'requires_action'));
        $this->stripe->queue($this->intent('pi_abandoned', 'canceled'));
        $this->stripe->queue($this->intent('pi_retry_ok', 'succeeded'));
        $this->pay($lease->customer, $payment, ['method' => 'card'])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(2, $payment->attempts()->count());
        $this->assertTrue(collect($this->stripe->requests)->contains(fn ($r) => str_contains($r['url'], '/payment_intents/pi_abandoned/cancel')));
    }

    public function test_an_unanswered_charge_is_repeated_with_the_same_key_never_replaced(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);
        $this->stripe->queueException(new ApiConnectionException('timed out'));

        $this->pay($lease->customer, $payment, ['method' => 'card'])->assertStatus(503);

        $attempt = $payment->attempts()->sole();
        $this->assertSame(PaymentAttempt::STATUS_INITIATED, $attempt->status);

        $this->stripe->queue($this->intent('pi_after_timeout', 'succeeded'));
        $this->pay($lease->customer, $payment, ['method' => 'card'])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame(1, $payment->attempts()->count());
        $keys = collect($this->stripe->requests)->filter(fn ($r) => str_contains($r['url'], '/payment_intents'))->keys();
        $this->assertCount(2, $keys);
        $this->assertSame($this->stripe->idempotencyKey($keys[0]), $this->stripe->idempotencyKey($keys[1]));
    }

    public function test_a_repeat_with_a_different_method_after_an_unanswered_charge_is_refused(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);
        $this->stripe->queueException(new ApiConnectionException('timed out'));
        $this->pay($lease->customer, $payment, ['method' => 'card'])->assertStatus(503);
        $before = count($this->stripe->requests);

        $this->pay($lease->customer, $payment, ['method' => 'bank'])->assertStatus(422);

        $this->assertCount($before, $this->stripe->requests);
    }

    public function test_autopay_leaves_a_payment_the_customer_is_paying_alone_and_never_falls_back_for_it(): void
    {
        config(['billing.autopay_charging_enabled' => true]);
        $lease = $this->lease(['autopay_enabled' => true, 'autopay_primary_method' => 'ach']);
        $payment = $this->due($lease);
        $this->stripe->queueException(new ApiConnectionException('timed out'));
        $this->pay($lease->customer, $payment, ['method' => 'card'])->assertStatus(503);
        $before = count($this->stripe->requests);

        // The scheduled run sees the customer's unconfirmed press and does not start a charge beside it.
        $this->artisan('payments:charge-autopay')->assertSuccessful();
        $this->assertCount($before, $this->stripe->requests);

        // And when a customer's own card payment is declined the other method is not charged by AutoPay.
        $payment2 = $this->due($lease, ['due_date' => '2026-10-14']);
        $this->stripe->queue(['error' => [
            'message' => 'Your card was declined.',
            'type' => 'card_error',
            'code' => 'card_declined',
            'decline_code' => 'generic_decline',
            'payment_intent' => $this->intent('pi_declined_2', 'requires_payment_method'),
        ]], 402);
        $this->pay($lease->customer, $payment2, ['method' => 'card'])->assertOk()->assertJsonPath('data.status', 'failed');
        $afterDecline = count($this->stripe->requests);

        $this->artisan('payments:charge-autopay')->assertSuccessful();

        $this->assertCount($afterDecline, $this->stripe->requests);
        $this->assertSame(Payment::STATUS_FAILED, $payment2->fresh()->status);
    }
}

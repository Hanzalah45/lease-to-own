<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\PaymentPart;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Notifications\PaymentPartReceivedNotification;
use App\Notifications\PaymentStatusChangedNotification;
use App\Services\CardPricing;
use App\Services\PaymentSplitter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\WebhookSignature;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Split payments (client, Joel, 2026-10-19 checklist, sections 1 and 7): a payment paid in pieces, by different methods, now and later.
 * A real case: a customer who wanted to pay part of the deposit in cash and the rest on a card.
 */
class SplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
        config(['billing.autopay_charging_enabled' => true, 'services.stripe.webhook_secret' => 'whsec_test_secret_for_specs_only']);
        // Thursday the 15th, 10:00 in Texas.
        $this->travelTo(Carbon::parse('2026-10-15 15:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'name' => 'Stefany Staff']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /** A picked-up lease: rent $200 and no tax, so a monthly payment is $200.00; both a card and a bank account are saved. */
    private function lease(array $overrides = []): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Jane Doe']);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_split']);

        return LeaseAgreement::factory()->pickedUp()->create(array_merge([
            'customer_id' => $customer->id, 'monthly_rental_payment' => 200, 'sales_tax_rate' => 0, 'ldw_selected' => false, 'ldw_amount' => 0,
            'autopay_enabled' => false, 'stripe_bank_payment_method_id' => 'pm_bank', 'stripe_card_payment_method_id' => 'pm_card', 'autopay_primary_method' => 'card',
        ], $overrides));
    }

    private function due(LeaseAgreement $lease, array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 200, 'due_date' => '2026-10-10', 'status' => Payment::STATUS_PENDING,
        ], $overrides));
    }

    private function take(Payment $payment, array $body, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')->postJson("/api/admin/payments/{$payment->id}/parts", $body);
    }

    private function intent(string $id, string $status): array
    {
        return ['id' => $id, 'object' => 'payment_intent', 'status' => $status];
    }

    private function webhook(string $type, array $object): TestResponse
    {
        $payload = json_encode(['id' => 'evt_'.Str::random(16), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => WebhookSignature::generateSignatureHeader($payload, 'whsec_test_secret_for_specs_only'),
        ], $payload);
    }

    private function fee(int $bankCents): float
    {
        return CardPricing::feeCents($bankCents) / 100;
    }

    // ---- by hand: cash, check, other ----

    public function test_a_cash_part_leaves_the_rest_due_and_tells_the_customer_what_is_left(): void
    {
        Notification::fake();
        $payment = $this->due($this->lease());

        $this->take($payment, ['method' => 'cash', 'amount' => 80, 'note' => 'At the counter'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.remaining_amount', 120)
            ->assertJsonPath('data.chargeable_amount', 120)
            ->assertJsonPath('data.parts.0.method', 'cash')
            ->assertJsonPath('data.parts.0.status', 'paid')
            ->assertJsonPath('data.parts.0.recorded_by.name', 'Stefany Staff');

        $part = PaymentPart::firstOrFail();
        $this->assertSame('80.00', $part->amount);
        $this->assertSame('2026-10-15', $part->paid_date->toDateString());
        $this->assertSame('staff', $part->started_by);
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        Notification::assertSentTo($payment->leaseAgreement->customer, PaymentPartReceivedNotification::class);
        Notification::assertSentTo($this->admin, PaymentPartReceivedNotification::class);
        Notification::assertNotSentTo($payment->leaseAgreement->customer, PaymentStatusChangedNotification::class);
        $audit = PaymentAuditLog::where('action', 'split.part_recorded')->firstOrFail();
        $this->assertSame('cash', $audit->method);
        $this->assertSame('80.00', $audit->amount);
        $this->assertSame($this->admin->id, $audit->user_id);
    }

    public function test_the_last_cash_part_pays_the_payment_in_one_method(): void
    {
        Notification::fake();
        $payment = $this->due($this->lease());
        $this->take($payment, ['method' => 'cash', 'amount' => 80])->assertCreated();

        $this->take($payment, ['method' => 'check', 'amount' => 120])->assertCreated()->assertJsonPath('data.status', 'paid');
        $this->assertSame('split', $payment->fresh()->method, 'Cash and a check are different methods.');

        $second = $this->due($payment->leaseAgreement, ['due_date' => '2026-11-10']);
        $this->take($second, ['method' => 'cash', 'amount' => 50])->assertCreated();
        $this->take($second, ['method' => 'cash', 'amount' => 150])->assertCreated()->assertJsonPath('data.status', 'paid');

        $second->refresh();
        $this->assertSame('cash', $second->method);
        $this->assertSame('2026-10-15', $second->paid_date->toDateString());
        $this->assertEqualsWithDelta(0.0, (float) $second->card_fee_amount, 0.001);
        Notification::assertSentTo($payment->leaseAgreement->customer, PaymentStatusChangedNotification::class);
        // One month paid, not two: the pieces are not payments of their own.
        $this->assertSame(2, $payment->leaseAgreement->fresh()->paymentsMadeCount());
    }

    public function test_a_part_cannot_be_more_than_is_left_or_nothing(): void
    {
        $payment = $this->due($this->lease());
        $this->take($payment, ['method' => 'cash', 'amount' => 150])->assertCreated();

        $this->take($payment, ['method' => 'cash', 'amount' => 50.01])->assertStatus(422)->assertJsonPath('message', 'At most $50.00 can still be paid toward this payment.');
        $this->take($payment, ['method' => 'cash', 'amount' => 0])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->take($payment, ['method' => 'bitcoin', 'amount' => 10])->assertStatus(422)->assertJsonValidationErrors('method');
        $this->take($payment, ['method' => 'cash', 'amount' => 10, 'note' => str_repeat('a', 301)])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->assertSame(1, PaymentPart::count());
    }

    public function test_only_a_payment_waiting_to_be_paid_of_a_kind_that_can_be_split_takes_parts(): void
    {
        $lease = $this->lease();
        $paid = $this->due($lease, ['status' => Payment::STATUS_PAID, 'paid_date' => '2026-10-01']);
        $extra = $this->due($lease, ['type' => Payment::TYPE_EXTRA_PAYMENT]);
        $ended = $this->lease();
        $endedDue = $this->due($ended);
        $ended->forceFill(['ended_at' => now(), 'ended_reason' => 'returned'])->save();

        $this->take($paid, ['method' => 'cash', 'amount' => 10])->assertStatus(422)->assertJsonPath('message', 'This payment is not waiting to be paid.');
        $this->take($extra, ['method' => 'cash', 'amount' => 10])->assertStatus(422)->assertJsonPath('message', 'A payment of this kind cannot be paid in parts.');
        $this->take($endedDue, ['method' => 'cash', 'amount' => 10])->assertStatus(422)->assertJsonPath('message', 'This lease has ended.');
        $this->assertSame(0, PaymentPart::count());
    }

    public function test_only_staff_with_payment_tracking_can_take_parts(): void
    {
        $payment = $this->due($this->lease());
        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);

        $this->take($payment, ['method' => 'cash', 'amount' => 10], $reviewer)->assertForbidden();
        $this->take($payment, ['method' => 'cash', 'amount' => 10], $payment->leaseAgreement->customer)->assertForbidden();
        $this->assertSame(0, PaymentPart::count());
    }

    // ---- by card or bank account ----

    public function test_cash_and_a_card_split_a_payment_and_it_carries_the_card_fee_and_the_split_method(): void
    {
        Notification::fake();
        $payment = $this->due($this->lease());
        $this->take($payment, ['method' => 'cash', 'amount' => 80])->assertCreated();
        $fee = $this->fee(12000);
        $this->stripe->queue($this->intent('pi_part_card', 'succeeded'));

        $this->take($payment, ['method' => 'card', 'amount' => 120, 'expected_total_cents' => 12000 + (int) round($fee * 100), 'customer_agreed' => true])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('status', 'paid');

        $payment->refresh();
        $this->assertSame('split', $payment->method);
        $this->assertEqualsWithDelta($fee, (float) $payment->card_fee_amount, 0.001);
        $this->assertEqualsWithDelta(200 + $fee, $payment->totalCharged(), 0.001);
        $this->assertCount(2, $payment->parts);
        $card = $payment->parts->last();
        $this->assertSame('card', $card->method);
        $this->assertSame('pi_part_card', $card->stripe_payment_intent_id);
        $this->assertSame('staff', $card->started_by);

        $request = $this->stripe->requests[0];
        $this->assertSame(12000 + (int) round($fee * 100), (int) $request['params']['amount']);
        $this->assertSame('pm_card', $request['params']['payment_method']);
        $this->assertSame('payment-part-'.$card->id, $this->stripe->idempotencyKey(0));
        $this->assertSame((string) $card->id, (string) $request['params']['metadata']['payment_part_id']);
        Notification::assertSentTo($payment->leaseAgreement->customer, PaymentStatusChangedNotification::class);
    }

    public function test_a_charge_needs_the_customers_agreement_and_the_price_they_saw(): void
    {
        $payment = $this->due($this->lease());

        $this->take($payment, ['method' => 'card', 'amount' => 50])->assertStatus(422)->assertJsonValidationErrors('customer_agreed');
        $this->take($payment, ['method' => 'card', 'amount' => 50, 'customer_agreed' => true, 'expected_total_cents' => 5000])
            ->assertStatus(409)
            ->assertJsonPath('message', 'The amount changed. Please review the new total and try again.');
        $this->assertCount(0, $this->stripe->requests);
        $this->assertSame(0, PaymentPart::count());
    }

    public function test_a_bank_part_is_pending_until_the_bank_confirms_and_then_finishes_the_payment(): void
    {
        $payment = $this->due($this->lease());
        $this->take($payment, ['method' => 'cash', 'amount' => 50])->assertCreated();
        $this->stripe->queue($this->intent('pi_part_bank', 'processing'));

        $this->take($payment, ['method' => 'bank', 'amount' => 150, 'customer_agreed' => true])
            ->assertCreated()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.chargeable_amount', 0)
            ->assertJsonPath('data.remaining_amount', 150);

        // The money is on its way: nothing more can be asked for, and AutoPay or Pay now cannot charge it again.
        $this->take($payment, ['method' => 'cash', 'amount' => 1])->assertStatus(422);

        $this->webhook('payment_intent.succeeded', $this->intent('pi_part_bank', 'succeeded'))->assertOk();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('split', $payment->method);
        $this->assertSame(['paid', 'paid'], $payment->parts->pluck('status')->all());
    }

    public function test_a_bank_part_that_fails_gives_the_money_back_to_be_asked_for_again(): void
    {
        $payment = $this->due($this->lease());
        $this->stripe->queue($this->intent('pi_part_bad', 'processing'));
        $this->take($payment, ['method' => 'bank', 'amount' => 100, 'customer_agreed' => true])->assertCreated();

        $this->webhook('payment_intent.payment_failed', $this->intent('pi_part_bad', 'requires_payment_method') + [
            'last_payment_error' => ['code' => 'insufficient_funds', 'message' => 'The account has insufficient funds.'],
        ])->assertOk();

        $part = PaymentPart::firstOrFail();
        $this->assertSame('failed', $part->status);
        $this->assertSame('The account has insufficient funds.', $part->failure_message);
        $payment->refresh();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertEqualsWithDelta(200.0, $payment->chargeableAmount(), 0.001);
        $this->assertEqualsWithDelta(200.0, $payment->remainingAmount(), 0.001);
    }

    public function test_a_declined_card_part_counts_for_nothing(): void
    {
        $payment = $this->due($this->lease());
        $this->stripe->queue(['error' => [
            'message' => 'Your card was declined.', 'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'generic_decline',
            'payment_intent' => $this->intent('pi_part_declined', 'requires_payment_method'),
        ]], 402);

        $this->take($payment, ['method' => 'card', 'amount' => 100, 'customer_agreed' => true])->assertStatus(422)->assertJsonPath('message', 'Your card was declined.');

        $part = PaymentPart::firstOrFail();
        $this->assertSame('failed', $part->status);
        $this->assertEqualsWithDelta(200.0, $payment->fresh()->chargeableAmount(), 0.001);
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_a_charge_with_no_answer_blocks_another_until_staff_have_checked_and_the_webhook_can_still_settle_it(): void
    {
        $payment = $this->due($this->lease());
        $this->stripe->queueException(new ApiConnectionException('timed out'));

        $this->take($payment, ['method' => 'card', 'amount' => 100, 'customer_agreed' => true])->assertStatus(503);

        $stuck = PaymentPart::firstOrFail();
        $this->assertSame('pending', $stuck->status);
        $this->assertNull($stuck->stripe_payment_intent_id);
        $this->assertSame(PaymentSplitter::OUTCOME_UNKNOWN, $stuck->failure_message);
        $this->take($payment, ['method' => 'cash', 'amount' => 10])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'no answer yet'));

        // It did go through after all: Stripe tells us, with the piece number the call carried.
        $this->webhook('payment_intent.succeeded', $this->intent('pi_late', 'succeeded') + ['metadata' => ['payment_part_id' => (string) $stuck->id]])->assertOk();

        $stuck->refresh();
        $this->assertSame('paid', $stuck->status);
        $this->assertSame('pi_late', $stuck->stripe_payment_intent_id);
        $this->assertEqualsWithDelta(100.0, $payment->fresh()->remainingAmount(), 0.001);
    }

    public function test_a_charge_with_no_answer_can_be_cancelled_once_checked(): void
    {
        $payment = $this->due($this->lease());
        $this->stripe->queueException(new ApiConnectionException('timed out'));
        $this->take($payment, ['method' => 'card', 'amount' => 100, 'customer_agreed' => true])->assertStatus(503);
        $stuck = PaymentPart::firstOrFail();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payment-parts/{$stuck->id}/cancel")->assertOk();

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->take($payment, ['method' => 'cash', 'amount' => 10])->assertCreated();

        // A piece that has an answer cannot be cancelled.
        $paid = PaymentPart::where('method', 'cash')->firstOrFail();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payment-parts/{$paid->id}/cancel")->assertStatus(422);
    }

    public function test_a_locked_method_cannot_take_a_part(): void
    {
        $lease = $this->lease(['card_locked_at' => now(), 'card_lock_reason' => 'Fraud suspected']);
        $payment = $this->due($lease);

        $this->take($payment, ['method' => 'card', 'amount' => 50, 'customer_agreed' => true])->assertStatus(422)->assertJsonPath('message', 'That payment method is locked. Please contact us.');
        $this->assertCount(0, $this->stripe->requests);
    }

    // ---- the rest paid the ordinary way ----

    public function test_autopay_charges_only_what_is_left_and_the_payment_ends_as_a_split(): void
    {
        $lease = $this->lease(['autopay_enabled' => true]);
        $payment = $this->due($lease, ['due_date' => '2026-10-15']);
        $this->take($payment, ['method' => 'cash', 'amount' => 120])->assertCreated();
        $fee = $this->fee(8000);
        $this->stripe->queue($this->intent('pi_rest', 'succeeded'));

        $this->artisan('payments:charge-autopay')->assertSuccessful();

        // Only the $80 that was left, at the card price.
        $this->assertSame(8000 + (int) round($fee * 100), (int) $this->stripe->requests[0]['params']['amount']);
        $payment->refresh();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('split', $payment->method);
        $this->assertEqualsWithDelta($fee, (float) $payment->card_fee_amount, 0.001);
        $this->assertNull($payment->stripe_payment_intent_id, 'The charge now belongs to its piece.');
        $this->assertCount(2, $payment->parts);
        $last = $payment->parts->last();
        $this->assertSame('card', $last->method);
        $this->assertSame('80.00', $last->amount);
        $this->assertSame('pi_rest', $last->stripe_payment_intent_id);
        $this->assertSame('system', $last->started_by);
        $this->assertEqualsWithDelta(200.0, $payment->parts->sum('amount'), 0.001);
    }

    public function test_autopay_waits_while_a_part_is_still_clearing(): void
    {
        $lease = $this->lease(['autopay_enabled' => true]);
        $payment = $this->due($lease, ['due_date' => '2026-10-15']);
        $this->stripe->queue($this->intent('pi_part_slow', 'processing'));
        $this->take($payment, ['method' => 'bank', 'amount' => 50, 'customer_agreed' => true])->assertCreated();

        $this->artisan('payments:charge-autopay')->assertSuccessful();

        $this->assertCount(1, $this->stripe->requests, 'Nothing more was charged.');
    }

    public function test_marking_a_part_paid_payment_paid_by_hand_records_the_rest_as_its_last_part(): void
    {
        $payment = $this->due($this->lease());
        $this->take($payment, ['method' => 'cash', 'amount' => 60])->assertCreated();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/payments/{$payment->id}", ['status' => 'paid', 'method' => 'check'])->assertOk();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('split', $payment->method);
        $this->assertSame(['cash', 'check'], $payment->parts->pluck('method')->all());
        $this->assertSame(['60.00', '140.00'], $payment->parts->pluck('amount')->all());
    }

    // ---- pay now, by the customer ----

    private function asCustomer(LeaseAgreement $lease)
    {
        return $this->actingAs($lease->customer, 'sanctum');
    }

    public function test_a_customer_pays_part_of_what_is_due_and_sees_what_remains(): void
    {
        Notification::fake();
        $lease = $this->lease();
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_cust_part', 'succeeded'));
        $fee = $this->fee(5000);

        $this->asCustomer($lease)->postJson("/api/customer/payments/{$payment->id}/pay", ['method' => 'card', 'amount' => 50, 'expected_total_cents' => 5000 + (int) round($fee * 100)])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment.remaining_amount', 150);

        $part = PaymentPart::firstOrFail();
        $this->assertSame('customer', $part->started_by);
        $this->assertSame('50.00', $part->amount);
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        // No staff and no Stripe ids reach the customer.
        $list = $this->asCustomer($lease)->getJson('/api/customer/payments')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $payment->id);
        $this->assertSame(['id', 'method', 'amount', 'card_fee_amount', 'status', 'paid_date', 'created_at'], array_keys($row['parts'][0]));
        $this->assertEqualsWithDelta(150.0, $row['pay']['chargeable_amount'], 0.001);
        $this->assertEqualsWithDelta(150.0, $row['pay']['prices']['bank'], 0.001);
        $this->assertEqualsWithDelta(5.0, $row['pay']['min_piece'], 0.001);
        $this->assertTrue($row['pay']['can_pay_in_parts']);
    }

    public function test_a_customers_part_is_not_smaller_than_the_minimum_unless_it_is_everything_left(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);

        $this->asCustomer($lease)->postJson("/api/customer/payments/{$payment->id}/pay", ['method' => 'bank', 'amount' => 4.99])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A part payment is at least $5.00, unless it is everything that is left.');

        // Everything left, however small, is allowed: here $2 after $198 in cash. It is the rest of the payment, so it is charged the
        // ordinary way (one charge for what is left) and the payment ends as a split of the cash and the bank debit.
        $this->take($payment, ['method' => 'cash', 'amount' => 198])->assertCreated();
        $this->stripe->queue($this->intent('pi_last', 'succeeded'));
        $this->asCustomer($lease)->postJson("/api/customer/payments/{$payment->id}/pay", ['method' => 'bank', 'amount' => 2])->assertOk();
        $this->assertSame(200, (int) $this->stripe->requests[0]['params']['amount']);
        $payment->refresh();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('split', $payment->method);
        $this->assertSame(['198.00', '2.00'], $payment->parts->pluck('amount')->all());
    }

    public function test_paying_the_whole_amount_with_an_amount_field_is_the_ordinary_payment(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_whole', 'succeeded'));

        $this->asCustomer($lease)->postJson("/api/customer/payments/{$payment->id}/pay", ['method' => 'bank', 'amount' => 200])->assertOk();

        $this->assertSame(0, PaymentPart::count());
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_another_customer_cannot_pay_part_of_this_payment(): void
    {
        $payment = $this->due($this->lease());
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($other, 'sanctum')->postJson("/api/customer/payments/{$payment->id}/pay", ['method' => 'bank', 'amount' => 50])->assertNotFound();
    }

    // ---- a deposit paid partly in cash and partly by card ----

    private function depositApplication(): Application
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_dep']);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => Application::STATUS_WAITING_DEPOSIT, 'deposit_received' => false]);
        $lease = LeaseAgreement::factory()->create([
            'application_id' => $application->id, 'customer_id' => $customer->id, 'equipment_unit_id' => null, 'security_deposit' => 300,
            'monthly_rental_payment' => 200, 'sales_tax_rate' => 0, 'ldw_selected' => false, 'ldw_amount' => 0,
            'stripe_bank_payment_method_id' => 'pm_bank', 'stripe_card_payment_method_id' => 'pm_card', 'autopay_primary_method' => 'card',
        ]);
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signed_at' => now()]);

        return $application;
    }

    public function test_part_of_the_deposit_in_cash_and_the_rest_charged_to_a_card(): void
    {
        $application = $this->depositApplication();

        // $200 in cash: the deposit row is made from what the lease says is due.
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/payment-parts", ['type' => 'deposit', 'method' => 'cash', 'amount' => 200])
            ->assertCreated()
            ->assertJsonPath('data.type', 'deposit')
            ->assertJsonPath('data.amount', '300.00')
            ->assertJsonPath('data.remaining_amount', 100);
        $deposit = Payment::where('type', 'deposit')->firstOrFail();
        $this->assertSame(Payment::STATUS_PENDING, $deposit->status);
        $this->assertFalse((bool) $application->fresh()->deposit_received);

        // The rest through the ordinary "charge the deposit" button: it charges only what is left, as the last part of the same payment.
        $fee = $this->fee(10000);
        $this->stripe->queue($this->intent('pi_dep_rest', 'succeeded'));
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/charge-deposit", [
            'method' => 'card', 'expected_total_cents' => 10000 + (int) round($fee * 100), 'customer_agreed' => true,
        ])->assertOk();

        $this->assertSame(1, Payment::where('type', 'deposit')->count(), 'One deposit row, not two.');
        $deposit->refresh();
        $this->assertSame(Payment::STATUS_PAID, $deposit->status);
        $this->assertSame('split', $deposit->method);
        $this->assertEqualsWithDelta($fee, (float) $deposit->card_fee_amount, 0.001);
        $this->assertSame(10000 + (int) round($fee * 100), (int) $this->stripe->requests[0]['params']['amount']);
        $this->assertTrue((bool) $application->fresh()->deposit_received, 'The deposit counts as received once it is all in.');
    }

    public function test_a_deposit_or_pickup_balance_that_is_already_paid_takes_no_more_parts(): void
    {
        $application = $this->depositApplication();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/payment-parts", ['type' => 'deposit', 'method' => 'cash', 'amount' => 300])->assertCreated();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/payment-parts", ['type' => 'deposit', 'method' => 'cash', 'amount' => 10])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The deposit has already been paid.');
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/payment-parts", ['type' => 'rental', 'method' => 'cash', 'amount' => 10])->assertStatus(422);

        // The pickup balance (tracking fee and first month) is its own payment.
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/payment-parts", ['type' => 'pickup_balance', 'method' => 'cash', 'amount' => 100])
            ->assertCreated()
            ->assertJsonPath('data.amount', '350.00');
    }

    // ---- refunds, part by part ----

    private function paidInTwoParts(): Payment
    {
        $payment = $this->due($this->lease());
        $this->take($payment, ['method' => 'cash', 'amount' => 80])->assertCreated();
        $this->stripe->queue($this->intent('pi_part_card', 'succeeded'));
        $this->take($payment, ['method' => 'card', 'amount' => 120, 'customer_agreed' => true])->assertCreated();

        return $payment->fresh();
    }

    private function refund(Payment $payment, array $body): TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payments/{$payment->id}/refunds", array_merge(['reason' => 'customer_request'], $body));
    }

    public function test_a_split_payment_is_refunded_one_part_at_a_time_each_the_way_it_was_paid(): void
    {
        $payment = $this->paidInTwoParts();
        [$cash, $card] = $payment->parts->all();
        $cardTotal = $card->totalCharged();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);

        // Which part has to be said.
        $this->refund($payment, ['amount' => 10])->assertStatus(422)->assertJsonPath('message', 'This payment was paid in parts. Choose which part to refund.');
        $this->refund($payment, ['amount' => 10, 'part_id' => 99999])->assertNotFound();

        // The cash part goes back by hand, no Stripe call.
        $requests = count($this->stripe->requests);
        $this->refund($payment, ['amount' => 80, 'part_id' => $cash->id])->assertCreated()->assertJsonPath('refund.source', 'manual');
        $this->assertCount($requests, $this->stripe->requests);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);

        // The card part goes back through Stripe, against its own charge.
        $this->stripe->queue(['id' => 're_card', 'object' => 'refund', 'status' => 'succeeded', 'amount' => (int) round($cardTotal * 100), 'currency' => 'usd', 'payment_intent' => 'pi_part_card', 'metadata' => []]);
        $this->refund($payment, ['amount' => $cardTotal, 'part_id' => $card->id])->assertCreated()->assertJsonPath('data.status', 'refunded');
        $this->assertSame('pi_part_card', $this->stripe->requests[$requests]['params']['payment_intent']);

        $this->assertEqualsWithDelta(80 + $cardTotal, (float) PaymentRefund::sum('amount'), 0.001);
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
    }

    public function test_a_part_cannot_be_refunded_for_more_than_it_was(): void
    {
        $payment = $this->paidInTwoParts();
        $cash = $payment->parts->first();

        $this->refund($payment, ['amount' => 80.01, 'part_id' => $cash->id])->assertStatus(422)->assertJsonPath('message', 'You can refund at most $80.00 of this part.');
        $this->refund($payment, ['amount' => 30, 'part_id' => $cash->id])->assertCreated();
        $this->refund($payment, ['amount' => 50.01, 'part_id' => $cash->id])->assertStatus(422)->assertJsonPath('message', 'You can refund at most $50.00 of this part.');

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/payments/{$payment->id}")
            ->assertJsonPath('data.parts.0.refunded_total', 30)
            ->assertJsonPath('data.parts.0.refundable_amount', 50);
    }

    public function test_a_part_of_a_payment_still_open_can_only_be_taken_back_whole_and_then_counts_again_as_due(): void
    {
        $payment = $this->due($this->lease());
        $this->take($payment, ['method' => 'cash', 'amount' => 80])->assertCreated();
        $cash = PaymentPart::firstOrFail();

        $this->refund($payment, ['amount' => 30, 'part_id' => $cash->id])->assertStatus(422)->assertJsonPath('message', 'While the payment is still open, a part can only be refunded in full.');
        $this->refund($payment, ['amount' => 80, 'part_id' => $cash->id])->assertCreated();

        $this->assertSame('refunded', $cash->fresh()->status);
        $payment->refresh();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertEqualsWithDelta(200.0, $payment->remainingAmount(), 0.001);
    }

    public function test_a_refund_of_a_part_that_was_never_paid_or_of_an_unsplit_payment_with_a_part_is_refused(): void
    {
        $lease = $this->lease();
        $plain = $this->due($lease, ['status' => Payment::STATUS_PAID, 'paid_date' => '2026-10-01', 'method' => 'cash']);
        $other = $this->due($lease);
        $this->take($other, ['method' => 'cash', 'amount' => 10])->assertCreated();
        $strangerPart = PaymentPart::firstOrFail();

        $this->refund($plain, ['amount' => 10, 'part_id' => $strangerPart->id])->assertNotFound();
    }

    // ---- the reports ----

    public function test_the_report_counts_a_split_payment_by_the_method_of_each_part(): void
    {
        $payment = $this->paidInTwoParts();
        $cardTotal = $payment->parts->last()->totalCharged();

        $report = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/reports/sales-tax?from=2026-10-01&to=2026-10-31')->assertOk();

        $this->assertEqualsWithDelta(80.0, $report->json('data.by_method.cash'), 0.001);
        $this->assertEqualsWithDelta($cardTotal, $report->json('data.by_method.card'), 0.001);
        $this->assertArrayNotHasKey('split', $report->json('data.by_method'));
        $this->assertEqualsWithDelta(200 + $payment->card_fee_amount, $report->json('data.totals.total_collected'), 0.001);
        $this->assertSame(1, $report->json('data.totals.payments'));

        $csv = $this->actingAs($this->admin, 'sanctum')->get('/api/admin/reports/payments.csv?from=2026-10-01&to=2026-10-31')->streamedContent();
        $this->assertStringContainsString(',split,', $csv);
    }

    public function test_a_refunded_part_comes_off_its_own_method_in_the_report(): void
    {
        $payment = $this->paidInTwoParts();
        $cash = $payment->parts->first();
        $this->refund($payment, ['amount' => 80, 'part_id' => $cash->id])->assertCreated();

        $report = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/reports/sales-tax?from=2026-10-01&to=2026-10-31')->assertOk();

        $this->assertEqualsWithDelta(0.0, $report->json('data.by_method.cash'), 0.001);
    }

    public function test_a_part_taken_back_from_a_payment_that_never_got_paid_does_not_show_in_the_report(): void
    {
        $payment = $this->due($this->lease());
        $this->take($payment, ['method' => 'cash', 'amount' => 80])->assertCreated();
        $this->refund($payment, ['amount' => 80, 'part_id' => PaymentPart::firstOrFail()->id])->assertCreated();

        $report = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/reports/sales-tax?from=2026-10-01&to=2026-10-31')->assertOk();

        $this->assertSame([], $report->json('data.months'));
        $this->assertEqualsWithDelta(0.0, $report->json('data.totals.refunded'), 0.001);
    }
}

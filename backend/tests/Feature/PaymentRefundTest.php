<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentDispute;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Notifications\PaymentDisputedNotification;
use App\Notifications\PaymentRefundedNotification;
use App\Notifications\PaymentStatusChangedNotification;
use App\Services\PaymentRefunder;
use App\Services\PaymentReport;
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
 * Refunds and chargebacks (client, Joel, 2026-10-19: "we have to make sure that I'm able to give a refund"). Staff refund a paid
 * payment in full or in part; the money goes back through Stripe to where it came from, and a refund or dispute handled in the Stripe
 * dashboard shows up here too. Stripe is faked at the HTTP layer (FakeStripeHttpClient), nothing here touches the network.
 */
class PaymentRefundTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
        $this->travelTo(Carbon::parse('2026-10-20 15:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /** Rent $200 at 8.25% tax: a monthly payment is $216.50, of which $16.50 is sales tax. */
    private function lease(array $overrides = []): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Jane Doe']);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => Application::STATUS_FINISHED]);

        return LeaseAgreement::factory()->create(array_merge([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => null,
            'monthly_rental_payment' => 200,
            'sales_tax_rate' => 0.0825,
            'ldw_selected' => false,
            'ldw_amount' => 0,
            'additional_funds' => 0,
        ], $overrides));
    }

    /** A paid card payment of $216.50 plus a $6.50 card fee: $223.00 charged through Stripe. */
    private function cardPayment(?LeaseAgreement $lease = null, array $extra = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'lease_agreement_id' => ($lease ?? $this->lease())->id,
            'type' => Payment::TYPE_RENTAL,
            'amount' => 216.50,
            'card_fee_amount' => 6.50,
            'due_date' => '2026-10-15',
            'paid_date' => '2026-10-15',
            'status' => Payment::STATUS_PAID,
            'method' => 'card',
            'stripe_payment_intent_id' => 'pi_card',
        ], $extra));
    }

    private function stripeRefund(string $id, string $status, int $cents, string $intent = 'pi_card', array $extra = []): array
    {
        return $extra + ['id' => $id, 'object' => 'refund', 'status' => $status, 'amount' => $cents, 'currency' => 'usd', 'payment_intent' => $intent, 'metadata' => []];
    }

    private function refund(Payment $payment, array $body = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')->postJson("/api/admin/payments/{$payment->id}/refunds", array_merge([
            'amount' => 223.00,
            'reason' => 'customer_request',
        ], $body));
    }

    private function webhook(string $type, array $object): TestResponse
    {
        config(['services.stripe.webhook_secret' => 'whsec_test_secret_for_specs_only']);
        $payload = json_encode(['id' => 'evt_'.Str::random(16), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => WebhookSignature::generateSignatureHeader($payload, 'whsec_test_secret_for_specs_only'),
        ], $payload);
    }

    // ---- refunding through Stripe ----

    public function test_a_card_payment_is_refunded_in_full_through_stripe(): void
    {
        Notification::fake();
        $payment = $this->cardPayment();
        $customer = $payment->leaseAgreement->customer;
        $this->stripe->queue($this->stripeRefund('re_1', 'succeeded', 22300));

        $response = $this->refund($payment, ['note' => 'Returned the mower']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('refund.status', 'succeeded')
            ->assertJsonPath('data.refundable_amount', 0);
        $refund = PaymentRefund::firstOrFail();
        $this->assertSame('223.00', $refund->amount);
        $this->assertSame('portal', $refund->source);
        $this->assertSame('re_1', $refund->stripe_refund_id);
        $this->assertSame($this->admin->id, $refund->refunded_by);
        $this->assertNotNull($refund->refunded_at);

        // Stripe was asked for the whole charge, with a key that makes a repeat harmless.
        $request = $this->stripe->requests[0];
        $this->assertStringContainsString('/v1/refunds', $request['url']);
        $this->assertSame('pi_card', $request['params']['payment_intent']);
        $this->assertSame(22300, (int) $request['params']['amount']);
        $this->assertSame('portal-refund-'.$refund->id, $this->stripe->idempotencyKey(0));

        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        // The customer gets one message about the refund, not also the generic "payment update".
        Notification::assertSentTo($customer, PaymentRefundedNotification::class);
        Notification::assertNotSentTo($customer, PaymentStatusChangedNotification::class);
        Notification::assertSentTo($this->admin, PaymentRefundedNotification::class);
        $note = $payment->leaseAgreement->application->dealerNotes()->firstOrFail();
        $this->assertStringContainsString('Refunded $223.00', $note->text);
        $this->assertStringContainsString('Returned the mower', $note->text);
    }

    public function test_a_partial_refund_keeps_the_payment_paid_and_limits_what_is_left(): void
    {
        $payment = $this->cardPayment();
        $this->stripe->queue($this->stripeRefund('re_1', 'succeeded', 10000))->queue($this->stripeRefund('re_2', 'succeeded', 12300));

        $this->refund($payment, ['amount' => 100])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.refunded_total', 100)
            ->assertJsonPath('data.refundable_amount', 123);

        // More than is left is refused before Stripe hears of it.
        $this->refund($payment, ['amount' => 123.01])->assertStatus(422)->assertJsonPath('message', 'You can refund at most $123.00 of this payment.');
        $this->assertCount(1, $this->stripe->requests);

        $this->refund($payment, ['amount' => 123])->assertCreated()->assertJsonPath('data.status', 'refunded');
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertSame(2, PaymentRefund::count());
    }

    public function test_a_refund_needs_an_amount_a_listed_reason_and_a_note_for_other_and_a_paid_payment(): void
    {
        $payment = $this->cardPayment();

        $this->refund($payment, ['amount' => 0])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->refund($payment, ['amount' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->refund($payment, ['reason' => 'because'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->refund($payment, ['reason' => 'other'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->refund($payment, ['note' => str_repeat('a', 501)])->assertStatus(422)->assertJsonValidationErrors('note');

        $pending = $this->cardPayment(null, ['status' => Payment::STATUS_PENDING, 'stripe_payment_intent_id' => 'pi_other']);
        $this->refund($pending)->assertStatus(422)->assertJsonPath('message', 'Only a payment that was paid can be refunded.');

        $this->assertCount(0, $this->stripe->requests);
        $this->assertSame(0, PaymentRefund::count());
    }

    public function test_a_payment_recorded_by_hand_is_refunded_without_asking_stripe(): void
    {
        $payment = $this->cardPayment(null, ['method' => 'cash', 'card_fee_amount' => 0, 'stripe_payment_intent_id' => null]);

        $this->refund($payment, ['amount' => 216.50, 'reason' => 'posted_in_error'])
            ->assertCreated()
            ->assertJsonPath('refund.source', 'manual')
            ->assertJsonPath('data.status', 'refunded');

        $this->assertCount(0, $this->stripe->requests);
        $this->assertStringContainsString('given back outside the portal', $payment->leaseAgreement->application->dealerNotes()->firstOrFail()->text);
    }

    public function test_a_bank_refund_counts_when_stripe_says_it_is_done(): void
    {
        $payment = $this->cardPayment(null, ['method' => 'ach', 'card_fee_amount' => 0, 'stripe_payment_intent_id' => 'pi_bank']);
        $this->stripe->queue($this->stripeRefund('re_bank', 'pending', 21650, 'pi_bank'));

        $this->refund($payment, ['amount' => 216.50])
            ->assertCreated()
            ->assertJsonPath('refund.status', 'pending')
            ->assertJsonPath('data.status', 'paid')
            // It is on its way, so it already counts against what can be refunded.
            ->assertJsonPath('data.refundable_amount', 0);
        $this->refund($payment, ['amount' => 1])->assertStatus(422);

        $this->webhook('refund.updated', $this->stripeRefund('re_bank', 'succeeded', 21650, 'pi_bank'))->assertOk();

        $this->assertSame('succeeded', PaymentRefund::firstOrFail()->status);
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
    }

    public function test_a_bank_refund_that_fails_gives_the_amount_back_to_refund_again(): void
    {
        $payment = $this->cardPayment(null, ['method' => 'ach', 'card_fee_amount' => 0, 'stripe_payment_intent_id' => 'pi_bank']);
        $this->stripe->queue($this->stripeRefund('re_bank', 'pending', 21650, 'pi_bank'));
        $this->refund($payment, ['amount' => 216.50])->assertCreated();

        $this->webhook('refund.updated', $this->stripeRefund('re_bank', 'failed', 21650, 'pi_bank', ['failure_reason' => 'expired_or_canceled_card']))->assertOk();

        $refund = PaymentRefund::firstOrFail();
        $this->assertSame('failed', $refund->status);
        $this->assertSame('expired_or_canceled_card', $refund->failure_message);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertEqualsWithDelta(216.50, $payment->fresh(['refunds'])->refundableAmount(), 0.001);
    }

    public function test_stripe_refusing_the_refund_leaves_the_payment_alone(): void
    {
        $payment = $this->cardPayment();
        $this->stripe->queue(['error' => ['type' => 'invalid_request_error', 'code' => 'charge_already_refunded', 'message' => 'Charge ch_1 has already been refunded.']], 400);

        $this->refund($payment)->assertStatus(422)->assertJsonPath('message', 'Stripe did not make this refund: Charge ch_1 has already been refunded.');

        $refund = PaymentRefund::firstOrFail();
        $this->assertSame('failed', $refund->status);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertEqualsWithDelta(223.00, $payment->fresh(['refunds'])->refundableAmount(), 0.001);
    }

    public function test_a_refund_that_got_no_answer_blocks_a_second_one_until_staff_have_checked(): void
    {
        $payment = $this->cardPayment();
        $this->stripe->queueException(new ApiConnectionException('timed out'));

        $this->refund($payment)->assertStatus(503);

        $stuck = PaymentRefund::firstOrFail();
        $this->assertSame('pending', $stuck->status);
        $this->assertNull($stuck->stripe_refund_id);
        $this->assertSame(PaymentRefunder::OUTCOME_UNKNOWN, $stuck->failure_message);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);

        // Another press must not ask Stripe again while the first one is unknown.
        $this->refund($payment)->assertStatus(422);
        $this->assertCount(1, $this->stripe->requests);

        // Staff look in Stripe, see nothing was refunded, and cancel it here; now a new refund can be made.
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payment-refunds/{$stuck->id}/cancel")->assertOk();
        $this->assertSame('failed', $stuck->fresh()->status);
        $this->stripe->queue($this->stripeRefund('re_2', 'succeeded', 22300));
        $this->refund($payment)->assertCreated();
    }

    public function test_an_unanswered_refund_that_did_go_through_is_found_by_the_webhook(): void
    {
        $payment = $this->cardPayment();
        $this->stripe->queueException(new ApiConnectionException('timed out'));
        $this->refund($payment)->assertStatus(503);
        $stuck = PaymentRefund::firstOrFail();

        // Stripe did make it, and tells us with the refund number the call carried.
        $this->webhook('refund.created', $this->stripeRefund('re_late', 'succeeded', 22300, 'pi_card', ['metadata' => ['refund_id' => (string) $stuck->id]]))->assertOk();

        $this->assertSame(1, PaymentRefund::count());
        $stuck->refresh();
        $this->assertSame('re_late', $stuck->stripe_refund_id);
        $this->assertSame('succeeded', $stuck->status);
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
    }

    public function test_an_unanswered_refund_cannot_be_cancelled_once_stripe_has_answered(): void
    {
        $payment = $this->cardPayment();
        $this->stripe->queue($this->stripeRefund('re_1', 'succeeded', 22300));
        $this->refund($payment)->assertCreated();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/payment-refunds/'.PaymentRefund::firstOrFail()->id.'/cancel')->assertStatus(422);
        $this->assertSame('succeeded', PaymentRefund::firstOrFail()->status);
    }

    // ---- what the refund changes ----

    public function test_refunding_an_extra_payment_takes_its_share_off_the_additional_funds(): void
    {
        $lease = $this->lease(['additional_funds' => 100]);
        $payment = $this->cardPayment($lease, ['type' => Payment::TYPE_EXTRA_PAYMENT, 'amount' => 100, 'card_fee_amount' => 3.00]);
        $this->stripe->queue($this->stripeRefund('re_1', 'succeeded', 5150))->queue($this->stripeRefund('re_2', 'succeeded', 5150));

        // Half of the $103 charged is half of the $100 that was added to the funds (the card fee never was).
        $this->refund($payment, ['amount' => 51.50])->assertCreated()->assertJsonPath('data.status', 'paid');
        $this->assertEqualsWithDelta(50.00, (float) $lease->fresh()->additional_funds, 0.001);

        $this->refund($payment, ['amount' => 51.50])->assertCreated()->assertJsonPath('data.status', 'refunded');
        // The status change to refunded must not take the funds off a second time.
        $this->assertEqualsWithDelta(0.0, (float) $lease->fresh()->additional_funds, 0.001);
    }

    public function test_the_first_month_row_that_only_mirrors_a_tracking_fee_charge_cannot_be_refunded(): void
    {
        $lease = $this->lease();
        $this->cardPayment($lease, ['type' => Payment::TYPE_PICKUP_BALANCE, 'amount' => 366.50, 'card_fee_amount' => 0, 'stripe_payment_intent_id' => 'pi_pickup']);
        $mirror = $this->cardPayment($lease, ['stripe_payment_intent_id' => null, 'method' => null, 'card_fee_amount' => 0]);

        $this->refund($mirror, ['amount' => 216.50])->assertStatus(422);
        $this->assertSame(0, PaymentRefund::count());
    }

    public function test_a_refunded_payments_status_can_no_longer_be_edited_and_refunded_is_not_a_status_to_pick(): void
    {
        $payment = $this->cardPayment();
        $this->stripe->queue($this->stripeRefund('re_1', 'succeeded', 22300));
        $this->refund($payment)->assertCreated();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/payments/{$payment->id}", ['status' => 'paid'])->assertStatus(422);
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);

        $other = $this->cardPayment(null, ['stripe_payment_intent_id' => 'pi_x']);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/payments/{$other->id}", ['status' => 'refunded'])->assertStatus(422);
        $this->assertSame(Payment::STATUS_PAID, $other->fresh()->status);
    }

    public function test_only_staff_with_payment_tracking_can_refund(): void
    {
        $payment = $this->cardPayment();
        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);

        $this->refund($payment, [], $reviewer)->assertForbidden();
        $this->refund($payment, [], $payment->leaseAgreement->customer)->assertForbidden();
        $this->assertSame(0, PaymentRefund::count());
    }

    public function test_the_admin_payment_screens_carry_the_refunds(): void
    {
        $payment = $this->cardPayment();
        $this->stripe->queue($this->stripeRefund('re_1', 'succeeded', 10000));
        $this->refund($payment, ['amount' => 100])->assertCreated();

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.refunds.0.amount', '100.00')
            ->assertJsonPath('data.refunds.0.refunded_by.name', $this->admin->name)
            ->assertJsonPath('data.refundable_amount', 123);
    }

    // ---- refunds and chargebacks that start in Stripe ----

    public function test_a_refund_made_in_the_stripe_dashboard_is_recorded_once(): void
    {
        Notification::fake();
        $payment = $this->cardPayment();

        $this->webhook('refund.created', $this->stripeRefund('re_dash', 'succeeded', 5000))->assertOk();
        // Stripe also sends the update for the same refund: still one row.
        $this->webhook('refund.updated', $this->stripeRefund('re_dash', 'succeeded', 5000))->assertOk();

        $refund = PaymentRefund::firstOrFail();
        $this->assertSame(1, PaymentRefund::count());
        $this->assertSame('stripe', $refund->source);
        $this->assertSame('refunded_in_stripe', $refund->reason);
        $this->assertSame('50.00', $refund->amount);
        $this->assertNull($refund->refunded_by);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        Notification::assertSentToTimes($payment->leaseAgreement->customer, PaymentRefundedNotification::class, 1);
    }

    public function test_a_dashboard_refund_of_everything_marks_the_payment_refunded(): void
    {
        $payment = $this->cardPayment();

        $this->webhook('refund.created', $this->stripeRefund('re_all', 'succeeded', 22300))->assertOk();

        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
    }

    public function test_a_refund_for_something_the_portal_never_charged_is_ignored(): void
    {
        $this->cardPayment();

        $this->webhook('refund.created', $this->stripeRefund('re_x', 'succeeded', 5000, 'pi_unknown'))->assertOk();

        $this->assertSame(0, PaymentRefund::count());
    }

    public function test_charge_refunded_asks_stripe_for_the_refunds_of_that_payment(): void
    {
        $payment = $this->cardPayment();
        $this->stripe->queue(['object' => 'list', 'has_more' => false, 'data' => [$this->stripeRefund('re_a', 'succeeded', 5000)]]);

        $this->webhook('charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_card', 'amount_refunded' => 5000])->assertOk();

        $this->assertStringContainsString('/v1/refunds', $this->stripe->requests[0]['url']);
        $this->assertSame('pi_card', $this->stripe->requests[0]['params']['payment_intent']);
        $this->assertSame('re_a', PaymentRefund::firstOrFail()->stripe_refund_id);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    private function dispute(string $status, array $extra = []): array
    {
        return $extra + [
            'id' => 'dp_1',
            'object' => 'dispute',
            'amount' => 22300,
            'currency' => 'usd',
            'reason' => 'fraudulent',
            'status' => $status,
            'payment_intent' => 'pi_card',
            'created' => Carbon::parse('2026-10-19 12:00:00', 'UTC')->timestamp,
            'evidence_details' => ['due_by' => Carbon::parse('2026-10-30 06:59:59', 'UTC')->timestamp],
        ];
    }

    public function test_a_chargeback_tells_staff_and_a_lost_one_is_recorded_as_money_taken_back(): void
    {
        Notification::fake();
        $payment = $this->cardPayment();
        $customer = $payment->leaseAgreement->customer;

        $this->webhook('charge.dispute.created', $this->dispute('needs_response'))->assertOk();

        $row = PaymentDispute::firstOrFail();
        $this->assertSame('223.00', $row->amount);
        $this->assertSame('needs_response', $row->status);
        $this->assertTrue($row->isOpen());
        $this->assertSame('2026-10-30', $row->evidence_due_by->toDateString());
        Notification::assertSentTo($this->admin, PaymentDisputedNotification::class);
        $this->assertSame(0, PaymentRefund::count());
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);

        $this->webhook('charge.dispute.closed', $this->dispute('lost'))->assertOk();
        // The same outcome arriving again changes nothing.
        $this->webhook('charge.dispute.closed', $this->dispute('lost'))->assertOk();

        $this->assertSame(1, PaymentDispute::count());
        $this->assertFalse($row->fresh()->isOpen());
        $refund = PaymentRefund::firstOrFail();
        $this->assertSame(1, PaymentRefund::count());
        $this->assertSame('dispute', $refund->source);
        $this->assertSame('chargeback', $refund->reason);
        $this->assertSame('223.00', $refund->amount);
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        // A chargeback is the customer's own bank's doing: only staff are told it was lost.
        Notification::assertNotSentTo($customer, PaymentRefundedNotification::class);
        Notification::assertSentTo($this->admin, PaymentRefundedNotification::class);
        Notification::assertSentToTimes($this->admin, PaymentDisputedNotification::class, 1);
    }

    public function test_a_chargeback_that_is_won_takes_nothing_back(): void
    {
        $payment = $this->cardPayment();

        $this->webhook('charge.dispute.created', $this->dispute('needs_response'))->assertOk();
        $this->webhook('charge.dispute.closed', $this->dispute('won'))->assertOk();

        $this->assertFalse(PaymentDispute::firstOrFail()->isOpen());
        $this->assertSame('won', PaymentDispute::firstOrFail()->status);
        $this->assertSame(0, PaymentRefund::count());
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_a_lost_chargeback_never_takes_back_more_than_is_left_after_a_refund(): void
    {
        $payment = $this->cardPayment();
        $this->stripe->queue($this->stripeRefund('re_1', 'succeeded', 10000));
        $this->refund($payment, ['amount' => 100])->assertCreated();

        $this->webhook('charge.dispute.closed', $this->dispute('lost'))->assertOk();

        $this->assertEqualsWithDelta(223.00, (float) PaymentRefund::sum('amount'), 0.001);
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
    }

    // ---- the reports ----

    public function test_a_refund_is_a_negative_row_in_the_report_and_the_months_net_out(): void
    {
        $payment = $this->cardPayment(null, ['method' => 'ach', 'card_fee_amount' => 0, 'stripe_payment_intent_id' => 'pi_bank']);
        $this->stripe->queue($this->stripeRefund('re_1', 'succeeded', 10825, 'pi_bank'));
        $this->refund($payment, ['amount' => 108.25])->assertCreated();

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/reports/sales-tax?from=2026-10-01&to=2026-10-31')->assertOk();

        $month = collect($response->json('data.months'))->keyBy('month')['2026-10'];
        $this->assertSame(1, $month['payments']);
        $this->assertSame(1, $month['refunds']);
        $this->assertEqualsWithDelta(108.25, $month['refunded'], 0.001);
        // Half the payment was kept, and half of its tax.
        $this->assertEqualsWithDelta(108.25, $month['total_collected'], 0.001);
        $this->assertEqualsWithDelta(8.25, $month['sales_tax'], 0.001);
        $this->assertEqualsWithDelta(100.00, $month['taxable_sales'], 0.001);
        $this->assertEqualsWithDelta(108.25, $response->json('data.by_method.ach'), 0.001);

        $csv = $this->actingAs($this->admin, 'sanctum')->get('/api/admin/reports/payments.csv?from=2026-10-01&to=2026-10-31')->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('2026-10-15,payment,', $lines[1]);
        $this->assertStringStartsWith('2026-10-20,refund,', $lines[2]);
        $this->assertStringContainsString(',-100,0,-8.25,', $lines[2]);
    }

    public function test_a_payment_refunded_in_full_nets_to_nothing_in_its_month(): void
    {
        $payment = $this->cardPayment(null, ['method' => 'ach', 'card_fee_amount' => 0, 'stripe_payment_intent_id' => 'pi_bank']);
        $this->stripe->queue($this->stripeRefund('re_1', 'succeeded', 21650, 'pi_bank'));
        $this->refund($payment, ['amount' => 216.50])->assertCreated();

        $totals = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/reports/sales-tax?from=2026-10-01&to=2026-10-31')->json('data.totals');

        $this->assertEqualsWithDelta(0.0, $totals['total_collected'], 0.001);
        $this->assertEqualsWithDelta(0.0, $totals['sales_tax'], 0.001);
        $this->assertEqualsWithDelta(216.50, $totals['refunded'], 0.001);
    }

    public function test_refunds_in_pieces_add_up_to_the_payment_to_the_cent(): void
    {
        $payment = $this->cardPayment(null, ['method' => 'ach', 'card_fee_amount' => 0, 'stripe_payment_intent_id' => 'pi_bank']);
        $report = app(PaymentReport::class);
        $kept = ['taxable_sales' => 0.0, 'sales_tax' => 0.0, 'total_collected' => 0.0];

        foreach ([72.17, 72.17, 72.16] as $piece) {
            $refund = $payment->refunds()->create(['amount' => $piece, 'reason' => 'customer_request', 'source' => 'manual', 'status' => 'succeeded', 'refunded_at' => now()]);
            $split = $report->refundBreakdown($refund);
            foreach ($kept as $key => $sum) {
                $kept[$key] = round($sum + $split[$key], 2);
            }
            $this->assertEqualsWithDelta($piece, array_sum(array_intersect_key($split, array_flip(PaymentReport::PARTS))), 0.001, 'Each refund splits into exactly its own amount.');
        }

        $this->assertEqualsWithDelta(200.00, $kept['taxable_sales'], 0.001);
        $this->assertEqualsWithDelta(16.50, $kept['sales_tax'], 0.001);
        $this->assertEqualsWithDelta(216.50, $kept['total_collected'], 0.001);
    }
}

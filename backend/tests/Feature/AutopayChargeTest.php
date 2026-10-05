<?php

namespace Tests\Feature;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\RiskRedFlag;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Notifications\AutopayNeedsReviewNotification;
use App\Services\AutopayCharger;
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
 * Automatic monthly charging (client, Joel, 2026-10-05): AutopayCharger, the
 * payments:charge-autopay command, and the webhook's part in AutoPay.
 */
class AutopayChargeTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
        config(['billing.autopay_charging_enabled' => true]);
        // 10:00 in Texas on the 15th: past the 9am first-attempt hour.
        $this->travelTo(Carbon::parse('2026-10-15 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /** A picked-up lease with both methods on file; $primary is 'ach' or 'card'. */
    private function lease(string $primary = 'ach', array $overrides = []): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_autopay']);

        return LeaseAgreement::factory()->pickedUp()->create(array_merge([
            'customer_id' => $customer->id,
            'monthly_rental_payment' => 200,
            'sales_tax_rate' => 0,
            'autopay_enabled' => true,
            'stripe_bank_payment_method_id' => 'pm_bank',
            'stripe_card_payment_method_id' => 'pm_card',
            'autopay_primary_method' => $primary,
        ], $overrides));
    }

    private function due(LeaseAgreement $lease, array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_RENTAL,
            'amount' => 200,
            'due_date' => '2026-10-15',
        ], $overrides));
    }

    private function intent(string $id, string $status): array
    {
        return ['id' => $id, 'object' => 'payment_intent', 'status' => $status];
    }

    private function cardDecline(string $intentId = 'pi_declined'): array
    {
        return ['error' => [
            'message' => 'Your card was declined.',
            'type' => 'card_error',
            'code' => 'card_declined',
            'decline_code' => 'generic_decline',
            'payment_intent' => $this->intent($intentId, 'requires_payment_method'),
        ]];
    }

    private function runCharger(): void
    {
        $this->artisan('payments:charge-autopay')->assertSuccessful();
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

    // ---- the happy paths ----

    public function test_a_card_payment_is_charged_at_the_card_price_and_marked_paid(): void
    {
        $lease = $this->lease('card');
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_card_ok', 'succeeded'));

        $this->runCharger();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('card', $payment->method);
        $this->assertEqualsWithDelta(200.0, (float) $payment->amount, 0.001);
        $this->assertEqualsWithDelta(6.0, (float) $payment->card_fee_amount, 0.001);
        $this->assertSame('pi_card_ok', $payment->stripe_payment_intent_id);
        $this->assertSame('2026-10-15', $payment->paid_date->toDateString());

        $request = $this->stripe->requests[0];
        $this->assertSame(20600, (int) $request['params']['amount']);
        $this->assertSame('pm_card', $request['params']['payment_method']);
        $this->assertSame('card', $request['params']['payment_method_types'][0]);
        $this->assertTrue(filter_var($request['params']['off_session'], FILTER_VALIDATE_BOOLEAN));
        $this->assertSame('autopay-'.$payment->id.'-a1-card', $this->stripe->idempotencyKey(0));
        $this->assertEquals($payment->id, $request['params']['metadata']['payment_id']);

        $attempt = $payment->attempts()->sole();
        $this->assertSame(PaymentAttempt::STATUS_SUCCEEDED, $attempt->status);
        $this->assertSame(20600, $attempt->amount_cents);
        $this->assertSame(600, $attempt->fee_cents);
    }

    public function test_the_card_fee_never_counts_toward_ownership_progress(): void
    {
        $lease = $this->lease('card');
        $this->due($lease);
        $this->stripe->queue($this->intent('pi_card_ok', 'succeeded'));

        $this->runCharger();

        $this->assertEqualsWithDelta(200.0, (float) $lease->fresh()->rental_payments_paid_to_date, 0.001);
    }

    public function test_a_bank_payment_is_charged_at_the_bank_price_and_waits_for_the_webhook(): void
    {
        $lease = $this->lease('ach');
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_ach_1', 'processing'));

        $this->runCharger();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame('ach', $payment->method);
        $this->assertEqualsWithDelta(0.0, (float) $payment->card_fee_amount, 0.001);
        $this->assertSame('pi_ach_1', $payment->stripe_payment_intent_id);
        $this->assertSame(20000, (int) $this->stripe->requests[0]['params']['amount']);
        $this->assertSame('us_bank_account', $this->stripe->requests[0]['params']['payment_method_types'][0]);
        $this->assertSame(PaymentAttempt::STATUS_PROCESSING, $payment->attempts()->sole()->status);

        $this->webhook('payment_intent.succeeded', $this->intent('pi_ach_1', 'succeeded'))->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(PaymentAttempt::STATUS_SUCCEEDED, $payment->attempts()->sole()->status);
    }

    public function test_the_webhook_and_the_charge_response_agreeing_does_not_pay_twice(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $lease = $this->lease('card');
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_dup', 'succeeded'));
        $this->runCharger();

        $this->webhook('payment_intent.succeeded', $this->intent('pi_dup', 'succeeded'))->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        Notification::assertNotSentTo($admin, AutopayNeedsReviewNotification::class);
        $this->assertCount(1, $this->stripe->requests);
    }

    // ---- failures and the fallback ----

    public function test_a_declined_card_falls_back_to_the_bank_at_the_bank_price(): void
    {
        $lease = $this->lease('card');
        $payment = $this->due($lease);
        $this->stripe->queue($this->cardDecline(), 402);
        $this->stripe->queue($this->intent('pi_bank_fb', 'processing'));

        $this->runCharger();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame('ach', $payment->method);
        $this->assertEqualsWithDelta(0.0, (float) $payment->card_fee_amount, 0.001);
        $this->assertCount(2, $this->stripe->requests);
        $this->assertSame(20600, (int) $this->stripe->requests[0]['params']['amount']);
        $this->assertSame(20000, (int) $this->stripe->requests[1]['params']['amount']);
        $this->assertSame('autopay-'.$payment->id.'-a2-bank', $this->stripe->idempotencyKey(1));
        $this->assertSame(0, RiskRedFlag::where('type', RiskRedFlag::TYPE_FAILED_ACH)->count());

        [$first, $second] = $payment->attempts()->get()->all();
        $this->assertSame(PaymentAttempt::STATUS_FAILED, $first->status);
        $this->assertSame('generic_decline', $first->failure_code);
        $this->assertSame(PaymentAttempt::STATUS_PROCESSING, $second->status);
    }

    public function test_a_failed_bank_debit_reported_by_the_webhook_is_retried_on_the_card_at_the_card_price(): void
    {
        $lease = $this->lease('ach');
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_ach_bad', 'processing'));
        $this->runCharger();

        $this->webhook('payment_intent.payment_failed', $this->intent('pi_ach_bad', 'requires_payment_method') + [
            'last_payment_error' => ['code' => 'account_closed', 'message' => 'The bank account is closed.'],
        ])->assertOk();

        // The webhook only records: no Stripe call, the payment waits for the next run.
        $this->assertCount(1, $this->stripe->requests);
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(0, RiskRedFlag::where('type', RiskRedFlag::TYPE_FAILED_ACH)->count());

        $this->stripe->queue($this->intent('pi_card_fb', 'succeeded'));
        $this->runCharger();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('card', $payment->method);
        $this->assertEqualsWithDelta(6.0, (float) $payment->card_fee_amount, 0.001);
        $this->assertSame(20600, (int) $this->stripe->requests[1]['params']['amount']);
        $this->assertSame('account_closed', $payment->attempts()->first()->failure_code);
    }

    public function test_when_both_methods_fail_the_payment_fails_once_and_is_flagged_once(): void
    {
        Notification::fake();
        $lease = $this->lease('card');
        $payment = $this->due($lease);
        $this->stripe->queue($this->cardDecline('pi_card_bad'), 402);
        $this->stripe->queue($this->intent('pi_bank_bad', 'processing'));
        $this->runCharger();

        $this->webhook('payment_intent.payment_failed', $this->intent('pi_bank_bad', 'requires_payment_method'))->assertOk();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertSame(1, RiskRedFlag::where('type', RiskRedFlag::TYPE_FAILED_ACH)->count());

        // Later runs leave a failed payment alone: no automatic retries.
        $this->runCharger();
        $this->assertCount(2, $this->stripe->requests);
        $this->assertSame(1, RiskRedFlag::where('type', RiskRedFlag::TYPE_FAILED_ACH)->count());
    }

    public function test_a_failure_with_only_one_method_on_file_fails_the_payment_immediately(): void
    {
        $lease = $this->lease('card', ['stripe_bank_payment_method_id' => null]);
        $payment = $this->due($lease);
        $this->stripe->queue($this->cardDecline(), 402);

        // The summary reports the failed charge, not a skip.
        $this->artisan('payments:charge-autopay')->expectsOutputToContain('failed: 1')->assertSuccessful();

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertCount(1, $this->stripe->requests);
        $this->assertSame(1, RiskRedFlag::where('type', RiskRedFlag::TYPE_FAILED_ACH)->count());
    }

    public function test_an_off_session_charge_needing_customer_action_counts_as_a_failed_method(): void
    {
        $lease = $this->lease('card');
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_3ds', 'requires_action'));
        $this->stripe->queue(['id' => 'pi_3ds', 'object' => 'payment_intent', 'status' => 'canceled']);
        $this->stripe->queue($this->intent('pi_bank_fb', 'processing'));

        $this->runCharger();

        $this->assertStringEndsWith('/pi_3ds/cancel', $this->stripe->requests[1]['url']);
        $this->assertSame(20000, (int) $this->stripe->requests[2]['params']['amount']);
        $this->assertSame('ach', $payment->fresh()->method);
    }

    // ---- unknown outcomes ----

    public function test_a_timeout_keeps_the_attempt_open_and_the_next_run_replays_the_same_key(): void
    {
        $lease = $this->lease('card');
        $payment = $this->due($lease);
        $this->stripe->queueException(new ApiConnectionException('timed out'));

        $this->runCharger();

        $this->assertSame(PaymentAttempt::STATUS_INITIATED, $payment->attempts()->sole()->status);
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        // An unknown outcome must never fall back to the other method.
        $this->assertCount(1, $this->stripe->requests);

        $this->stripe->queue($this->intent('pi_replayed', 'succeeded'));
        $this->runCharger();

        $this->assertCount(2, $this->stripe->requests);
        $this->assertSame($this->stripe->idempotencyKey(0), $this->stripe->idempotencyKey(1));
        $this->assertSame($this->stripe->requests[0]['params']['amount'], $this->stripe->requests[1]['params']['amount']);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(1, $payment->attempts()->count());
    }

    public function test_an_unconfirmed_charge_too_old_to_replay_is_flagged_for_review_not_resent(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $lease = $this->lease('card');
        $payment = $this->due($lease);
        $this->stripe->queueException(new ApiConnectionException('timed out'));
        $this->runCharger();

        $this->travelTo(now()->addHours(AutopayCharger::REPLAY_WINDOW_HOURS + 1));
        $this->runCharger();

        $this->assertCount(1, $this->stripe->requests);
        Notification::assertSentTo($admin, AutopayNeedsReviewNotification::class);
    }

    // ---- the gates ----

    public function test_it_does_nothing_while_the_kill_switch_is_off(): void
    {
        config(['billing.autopay_charging_enabled' => false]);
        $lease = $this->lease('card');
        $payment = $this->due($lease);

        $this->runCharger();

        $this->assertCount(0, $this->stripe->requests);
        $this->assertSame(0, $payment->attempts()->count());
    }

    public function test_a_dry_run_reports_the_plan_without_calling_stripe_or_changing_anything(): void
    {
        config(['billing.autopay_charging_enabled' => false]);
        $lease = $this->lease('card');
        $payment = $this->due($lease);

        $this->artisan('payments:charge-autopay --dry-run')
            ->expectsOutputToContain("Payment #{$payment->id} (lease #{$lease->id}, due 2026-10-15, $200.00): would charge $206.00 by card (first, includes $6.00 card fee)")
            ->assertSuccessful();

        $this->assertCount(0, $this->stripe->requests);
        $this->assertSame(0, PaymentAttempt::count());
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_a_payment_due_tomorrow_is_not_charged_yet(): void
    {
        $lease = $this->lease('card');
        $this->due($lease, ['due_date' => '2026-10-16']);

        $this->runCharger();

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_payment_due_today_waits_until_nine_am_central(): void
    {
        $lease = $this->lease('card');
        $payment = $this->due($lease);
        // 8:30 in Texas.
        $this->travelTo(Carbon::parse('2026-10-15 13:30:00', 'UTC'));

        $this->runCharger();
        $this->assertCount(0, $this->stripe->requests);

        $this->stripe->queue($this->intent('pi_morning', 'succeeded'));
        $this->travelTo(Carbon::parse('2026-10-15 14:15:00', 'UTC'));
        $this->runCharger();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_an_overdue_payment_is_charged(): void
    {
        $lease = $this->lease('card');
        $payment = $this->due($lease, ['due_date' => '2026-10-10']);
        $this->stripe->queue($this->intent('pi_late', 'succeeded'));

        $this->runCharger();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_a_paused_lease_is_not_charged(): void
    {
        $lease = $this->lease('card', ['autopay_paused_at' => now()]);
        $this->due($lease);

        $this->runCharger();

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_lease_without_autopay_is_not_charged(): void
    {
        $lease = $this->lease('card', ['autopay_enabled' => false]);
        $this->due($lease);

        $this->runCharger();

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_lease_that_has_not_been_picked_up_is_not_charged(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $lease = LeaseAgreement::factory()->create([
            'customer_id' => $customer->id,
            'autopay_enabled' => true,
            'stripe_card_payment_method_id' => 'pm_card',
            'autopay_primary_method' => 'card',
        ]);
        $this->due($lease);

        $this->runCharger();

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_suspended_customer_is_not_charged(): void
    {
        $lease = $this->lease('card');
        $lease->customer->update(['status' => 'suspended']);
        $this->due($lease);

        $this->runCharger();

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_only_rental_payments_are_charged(): void
    {
        $lease = $this->lease('card');
        $this->due($lease, ['type' => Payment::TYPE_LATE_FEE]);
        $this->due($lease, ['type' => Payment::TYPE_PICKUP_BALANCE]);

        $this->runCharger();

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_payment_with_a_charge_already_in_flight_is_left_alone(): void
    {
        $lease = $this->lease('card');
        $this->due($lease, ['stripe_payment_intent_id' => 'pi_somewhere_else']);

        $this->runCharger();

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_payment_still_processing_is_not_charged_again(): void
    {
        $lease = $this->lease('ach');
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_ach_wait', 'processing'));
        $this->runCharger();

        $this->runCharger();
        $this->runCharger();

        $this->assertCount(1, $this->stripe->requests);
        $this->assertSame(1, $payment->attempts()->count());
    }

    public function test_a_debit_processing_for_days_is_checked_against_stripe(): void
    {
        $lease = $this->lease('ach');
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_ach_old', 'processing'));
        $this->runCharger();

        $this->travelTo(now()->addDays(4));
        $this->stripe->queue($this->intent('pi_ach_old', 'succeeded'));
        $this->runCharger();

        $this->assertSame('get', strtolower($this->stripe->requests[1]['method']));
        $this->assertStringEndsWith('/pi_ach_old', $this->stripe->requests[1]['url']);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    // ---- webhook edge cases ----

    public function test_a_success_for_a_payment_that_was_already_paid_alerts_staff(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $lease = $this->lease('ach');
        $payment = $this->due($lease);
        $this->stripe->queue($this->intent('pi_ach_dbl', 'processing'));
        $this->runCharger();

        // Staff record the payment by hand while the debit is still settling.
        $payment->refresh()->update(['status' => Payment::STATUS_PAID, 'paid_date' => '2026-10-15']);
        $this->webhook('payment_intent.succeeded', $this->intent('pi_ach_dbl', 'succeeded'))->assertOk();

        Notification::assertSentTo($admin, AutopayNeedsReviewNotification::class);
    }

    public function test_an_event_that_beats_the_charge_response_is_matched_by_metadata(): void
    {
        $lease = $this->lease('card');
        $payment = $this->due($lease);
        // The charge was claimed but the response (and so the intent id) was lost.
        $this->stripe->queueException(new ApiConnectionException('timed out'));
        $this->runCharger();
        $attempt = $payment->attempts()->sole();

        $this->webhook('payment_intent.succeeded', $this->intent('pi_from_webhook', 'succeeded') + [
            'metadata' => ['payment_id' => (string) $payment->id, 'attempt_no' => '1', 'type' => 'rental'],
        ])->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame('pi_from_webhook', $attempt->fresh()->stripe_payment_intent_id);
        $this->assertSame(1, StripeWebhookEvent::count());
    }

    // ---- admin retry ----

    public function test_staff_can_retry_a_failed_payment_which_starts_a_new_round(): void
    {
        $lease = $this->lease('card', ['stripe_bank_payment_method_id' => null]);
        $payment = $this->due($lease);
        $this->stripe->queue($this->cardDecline(), 402);
        $this->runCharger();
        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);

        $this->stripe->queue($this->intent('pi_retry_ok', 'succeeded'));
        $result = app(AutopayCharger::class)->retry($payment->fresh());

        $this->assertSame('charged', $result);
        $payment->refresh();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $attempts = $payment->attempts()->get();
        $this->assertCount(2, $attempts);
        $this->assertSame(2, $attempts->last()->round);
        $this->assertSame('autopay-'.$payment->id.'-a2-card', $this->stripe->idempotencyKey(1));
    }

    // ---- staff controls ----

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    public function test_staff_can_pause_and_resume_autopay_on_a_lease(): void
    {
        $lease = $this->lease('card');
        $payment = $this->due($lease);
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/autopay/pause")->assertOk();
        $this->assertNotNull($lease->fresh()->autopay_paused_at);
        $this->runCharger();
        $this->assertCount(0, $this->stripe->requests);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/autopay/resume")->assertOk();
        $this->assertNull($lease->fresh()->autopay_paused_at);
        $this->stripe->queue($this->intent('pi_after_resume', 'succeeded'));
        $this->runCharger();
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_pausing_a_lease_without_autopay_is_rejected(): void
    {
        $lease = $this->lease('card', ['autopay_enabled' => false]);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/admin/lease-agreements/{$lease->id}/autopay/pause")
            ->assertStatus(422);
    }

    public function test_a_customer_cannot_pause_or_retry(): void
    {
        $lease = $this->lease('card');
        $payment = $this->due($lease, ['status' => Payment::STATUS_FAILED]);

        $this->actingAs($lease->customer, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/autopay/pause")->assertForbidden();
        $this->actingAs($lease->customer, 'sanctum')->postJson("/api/admin/payments/{$payment->id}/retry-autopay")->assertForbidden();
    }

    public function test_staff_can_retry_a_failed_payment_over_the_api(): void
    {
        $lease = $this->lease('card');
        $payment = $this->due($lease, ['status' => Payment::STATUS_FAILED]);
        $this->stripe->queue($this->intent('pi_api_retry', 'succeeded'));

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/admin/payments/{$payment->id}/retry-autopay")
            ->assertOk()
            ->assertJsonPath('data.result', 'charged')
            ->assertJsonPath('data.payment.status', 'paid');
    }

    public function test_retry_is_refused_for_a_payment_that_has_not_failed_or_while_the_switch_is_off(): void
    {
        $lease = $this->lease('card');
        $pending = $this->due($lease);
        $failed = $this->due($lease, ['status' => Payment::STATUS_FAILED, 'due_date' => '2026-09-15']);
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/payments/{$pending->id}/retry-autopay")->assertStatus(422);

        config(['billing.autopay_charging_enabled' => false]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/payments/{$failed->id}/retry-autopay")->assertStatus(409);
        $this->assertCount(0, $this->stripe->requests);
    }
}

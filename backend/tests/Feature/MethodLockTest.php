<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\RiskRedFlag;
use App\Models\User;
use App\Notifications\PaymentSafetyNotification;
use App\Services\AutopayCharger;
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
 * Locking a saved bank account or card, the automatic lock after a hard decline, and the freeze while a chargeback is open (client, Joel,
 * 2026-10-19 checklist, sections 2, 4 and 11). A locked method stays on file and is never charged.
 */
class MethodLockTest extends TestCase
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
        $this->travelTo(Carbon::parse('2026-10-15 15:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'name' => 'Stefany Staff']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function lease(string $primary = 'ach', array $overrides = []): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Jane Doe']);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_lock']);

        return LeaseAgreement::factory()->pickedUp()->create(array_merge([
            'customer_id' => $customer->id, 'monthly_rental_payment' => 200, 'sales_tax_rate' => 0, 'autopay_enabled' => true,
            'stripe_bank_payment_method_id' => 'pm_bank', 'stripe_card_payment_method_id' => 'pm_card', 'autopay_primary_method' => $primary,
        ], $overrides));
    }

    private function due(LeaseAgreement $lease, array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 200, 'due_date' => '2026-10-15',
        ], $overrides));
    }

    private function intent(string $id, string $status): array
    {
        return ['id' => $id, 'object' => 'payment_intent', 'status' => $status];
    }

    private function declineWith(string $declineCode, string $intentId = 'pi_declined'): array
    {
        return ['error' => [
            'message' => 'Your card was declined.', 'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => $declineCode,
            'payment_intent' => $this->intent($intentId, 'requires_payment_method'),
        ]];
    }

    private function lock(LeaseAgreement $lease, string $type, bool $locked = true, ?string $reason = 'Customer reported it stolen'): TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/method-lock", array_filter([
            'type' => $type, 'locked' => $locked, 'reason' => $reason,
        ], fn ($v) => $v !== null));
    }

    private function webhook(string $type, array $object): TestResponse
    {
        $payload = json_encode(['id' => 'evt_'.Str::random(16), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => WebhookSignature::generateSignatureHeader($payload, 'whsec_test_secret_for_specs_only'),
        ], $payload);
    }

    // ---- locking by hand ----

    public function test_staff_lock_and_unlock_a_method_with_a_reason_and_it_is_recorded(): void
    {
        $lease = $this->lease();

        $this->lock($lease, 'card')->assertOk()->assertJsonPath('data.card_lock.reason', 'Customer reported it stolen')->assertJsonPath('data.bank_lock', null);

        $lease->refresh();
        $this->assertTrue($lease->isMethodLocked('card'));
        $this->assertFalse($lease->isMethodLocked('bank'));
        $this->assertSame($this->admin->id, $lease->card_locked_by);
        $audit = PaymentAuditLog::where('action', 'method.locked')->firstOrFail();
        $this->assertSame('staff', $audit->actor_type);
        $this->assertSame('card', $audit->method);

        $this->lock($lease, 'card', false, null)->assertOk()->assertJsonPath('data.card_lock', null);
        $this->assertFalse($lease->fresh()->isMethodLocked('card'));
        $this->assertSame(1, PaymentAuditLog::where('action', 'method.unlocked')->count());
    }

    public function test_locking_needs_a_reason_a_saved_method_and_something_not_already_locked(): void
    {
        $lease = $this->lease('ach', ['stripe_card_payment_method_id' => null]);

        $this->lock($lease, 'bank', true, null)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->lock($lease, 'bank', true, 'ab')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->lock($lease, 'bank', true, str_repeat('a', 201))->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->lock($lease, 'card')->assertStatus(422)->assertJsonPath('message', 'There is no card saved on this lease.');
        $this->lock($lease, 'bank', false, null)->assertStatus(422)->assertJsonPath('message', 'That method is not locked.');
        $this->lock($lease, 'bank')->assertOk();
        $this->lock($lease, 'bank')->assertStatus(422)->assertJsonPath('message', 'That method is already locked.');
    }

    public function test_only_staff_with_payment_tracking_can_lock(): void
    {
        $lease = $this->lease();
        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);

        foreach ([$lease->customer, $reviewer] as $user) {
            $this->actingAs($user, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/method-lock", ['type' => 'card', 'locked' => true, 'reason' => 'abc'])->assertForbidden();
        }
        $this->assertFalse($lease->fresh()->isMethodLocked('card'));
    }

    public function test_the_staff_view_shows_the_lock_and_the_customer_only_learns_that_it_is_locked(): void
    {
        $lease = $this->lease();
        $this->lock($lease, 'bank', true, 'Fraud suspected on this account')->assertOk();

        $staff = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/lease-agreements/{$lease->id}/autopay-methods")->assertOk();
        $this->assertSame('Fraud suspected on this account', $staff->json('data.bank_lock.reason'));
        $this->assertNull($staff->json('data.card_lock'));

        $customer = $this->actingAs($lease->customer, 'sanctum')->getJson("/api/customer/lease-agreements/{$lease->id}/payment-methods")->assertOk();
        $this->assertTrue($customer->json('data.bank_locked'));
        $this->assertFalse($customer->json('data.card_locked'));
        $this->assertStringNotContainsString('Fraud suspected', $customer->getContent());
    }

    // ---- a locked method is never charged ----

    public function test_autopay_skips_a_locked_primary_and_uses_the_other_method(): void
    {
        $lease = $this->lease('ach');
        $payment = $this->due($lease);
        $this->lock($lease, 'bank')->assertOk();
        $this->stripe->queue($this->intent('pi_card_ok', 'succeeded'));

        $this->artisan('payments:charge-autopay')->assertSuccessful();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame('card', $payment->fresh()->method);
        $this->assertSame('pm_card', $this->stripe->requests[0]['params']['payment_method']);
    }

    public function test_autopay_does_nothing_when_every_method_is_locked(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);
        $this->lock($lease, 'bank')->assertOk();
        $this->lock($lease, 'card')->assertOk();

        $this->assertSame('Every saved payment method is locked.', AutopayCharger::leaseBlocker($lease->fresh(['application', 'customer'])));
        $this->artisan('payments:charge-autopay')->assertSuccessful();

        $this->assertCount(0, $this->stripe->requests);
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_pay_now_refuses_a_locked_method_and_does_not_offer_it(): void
    {
        $lease = $this->lease('ach', ['autopay_enabled' => false]);
        $payment = $this->due($lease, ['due_date' => '2026-10-10']);
        $this->lock($lease, 'card')->assertOk();

        $options = $this->actingAs($lease->customer, 'sanctum')->getJson('/api/customer/payments')->assertOk()->json('data.0.pay');
        $this->assertSame(['bank'], $options['available_methods']);

        $this->actingAs($lease->customer, 'sanctum')->postJson("/api/customer/payments/{$payment->id}/pay", ['method' => 'card'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That payment method is locked. Please contact us.');
        $this->assertCount(0, $this->stripe->requests);
    }

    // ---- hard declines ----

    public function test_a_stolen_card_decline_locks_the_card_flags_the_customer_and_tells_staff(): void
    {
        Notification::fake();
        $lease = $this->lease('card');
        $payment = $this->due($lease);
        $this->stripe->queue($this->declineWith('stolen_card'), 402);
        $this->stripe->queue($this->intent('pi_bank_fb', 'processing'));

        $this->artisan('payments:charge-autopay')->assertSuccessful();

        $lease->refresh();
        $this->assertTrue($lease->isMethodLocked('card'));
        $this->assertFalse($lease->isMethodLocked('bank'));
        $this->assertStringContainsString('stolen_card', $lease->card_lock_reason);
        $this->assertSame(1, RiskRedFlag::where('type', RiskRedFlag::TYPE_HARD_DECLINE)->count());
        Notification::assertSentTo($this->admin, PaymentSafetyNotification::class);
        // The bank was still tried: the lock only takes the failed method out.
        $this->assertCount(2, $this->stripe->requests);
        $this->assertSame('system', PaymentAuditLog::where('action', 'method.locked')->firstOrFail()->actor_type);
        $this->assertSame('ach', $payment->fresh()->method);
    }

    public function test_a_closed_bank_account_reported_by_the_webhook_locks_the_bank(): void
    {
        $lease = $this->lease('ach');
        $this->due($lease);
        $this->stripe->queue($this->intent('pi_ach_bad', 'processing'));
        $this->artisan('payments:charge-autopay')->assertSuccessful();

        $this->webhook('payment_intent.payment_failed', $this->intent('pi_ach_bad', 'requires_payment_method') + [
            'object' => 'payment_intent',
            'last_payment_error' => ['code' => 'account_closed', 'message' => 'The bank account is closed.'],
        ])->assertOk();

        $this->assertTrue($lease->fresh()->isMethodLocked('bank'));
        $this->assertFalse($lease->fresh()->isMethodLocked('card'));
    }

    public function test_an_ordinary_decline_locks_nothing(): void
    {
        $lease = $this->lease('card');
        $this->due($lease);
        $this->stripe->queue($this->declineWith('insufficient_funds'), 402);
        $this->stripe->queue($this->intent('pi_bank_fb', 'processing'));

        $this->artisan('payments:charge-autopay')->assertSuccessful();

        $this->assertFalse($lease->fresh()->isMethodLocked('card'));
        $this->assertSame(0, RiskRedFlag::where('type', RiskRedFlag::TYPE_HARD_DECLINE)->count());
    }

    // ---- the chargeback freeze ----

    private function dispute(string $status, string $intent = 'pi_card_paid'): array
    {
        return [
            'id' => 'dp_lock', 'object' => 'dispute', 'amount' => 20600, 'currency' => 'usd', 'reason' => 'fraudulent', 'status' => $status,
            'payment_intent' => $intent, 'created' => Carbon::now()->timestamp,
            'evidence_details' => ['due_by' => Carbon::now()->addDays(10)->timestamp],
        ];
    }

    private function paidByCard(LeaseAgreement $lease): Payment
    {
        return Payment::factory()->create([
            'lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 200, 'card_fee_amount' => 6, 'method' => 'card',
            'stripe_payment_intent_id' => 'pi_card_paid', 'due_date' => '2026-10-01', 'paid_date' => '2026-10-01', 'status' => Payment::STATUS_PAID,
        ]);
    }

    public function test_an_open_chargeback_freezes_the_lease_until_it_is_decided(): void
    {
        Notification::fake();
        $lease = $this->lease();
        $this->paidByCard($lease);

        $this->webhook('charge.dispute.created', $this->dispute('needs_response'))->assertOk();

        $lease->refresh();
        $this->assertTrue($lease->isMethodLocked('card'), 'The card the disputed payment was made with is locked.');
        $this->assertFalse($lease->isMethodLocked('bank'));
        $this->assertNotNull($lease->autopay_paused_at);
        $this->assertSame('dp_lock', $lease->chargeback_dispute_id);
        $this->assertSame('card', $lease->chargeback_locked_method);
        $this->assertSame(1, RiskRedFlag::where('type', RiskRedFlag::TYPE_CHARGEBACK)->count());
        $this->assertSame(1, PaymentAuditLog::where('action', 'chargeback.frozen')->count());
        Notification::assertSentTo($this->admin, PaymentSafetyNotification::class);

        // The same dispute reported again changes nothing.
        $this->webhook('charge.dispute.updated', $this->dispute('under_review'))->assertOk();
        $this->assertSame(1, RiskRedFlag::where('type', RiskRedFlag::TYPE_CHARGEBACK)->count());
    }

    public function test_winning_the_chargeback_lifts_exactly_what_it_froze(): void
    {
        $lease = $this->lease();
        $this->paidByCard($lease);
        $this->webhook('charge.dispute.created', $this->dispute('needs_response'))->assertOk();

        $this->webhook('charge.dispute.closed', $this->dispute('won'))->assertOk();

        $lease->refresh();
        $this->assertFalse($lease->isMethodLocked('card'));
        $this->assertNull($lease->autopay_paused_at);
        $this->assertNull($lease->chargeback_dispute_id);
        $this->assertSame(1, PaymentAuditLog::where('action', 'chargeback.unfrozen')->count());
    }

    public function test_winning_leaves_alone_a_lock_or_pause_that_somebody_put_there_before(): void
    {
        $lease = $this->lease('ach', ['autopay_paused_at' => now()->subDay()]);
        $this->paidByCard($lease);
        $this->lock($lease, 'card', true, 'Locked by hand earlier')->assertOk();
        $this->webhook('charge.dispute.created', $this->dispute('needs_response'))->assertOk();

        $this->webhook('charge.dispute.closed', $this->dispute('won'))->assertOk();

        $lease->refresh();
        $this->assertTrue($lease->isMethodLocked('card'), 'A lock staff put there stays.');
        $this->assertNotNull($lease->autopay_paused_at, 'A pause staff put there stays.');
        $this->assertNull($lease->chargeback_dispute_id);
    }

    public function test_losing_the_chargeback_keeps_the_account_frozen_for_a_person_to_decide(): void
    {
        $lease = $this->lease();
        $this->paidByCard($lease);
        $this->webhook('charge.dispute.created', $this->dispute('needs_response'))->assertOk();

        $this->webhook('charge.dispute.closed', $this->dispute('lost'))->assertOk();

        $lease->refresh();
        $this->assertTrue($lease->isMethodLocked('card'));
        $this->assertNotNull($lease->autopay_paused_at);
        $this->assertSame('dp_lock', $lease->chargeback_dispute_id);
        $this->assertSame(0, PaymentAuditLog::where('action', 'chargeback.unfrozen')->count());
    }

    public function test_unlocking_by_hand_during_a_chargeback_means_winning_does_not_unlock_it_twice(): void
    {
        $lease = $this->lease();
        $this->paidByCard($lease);
        $this->webhook('charge.dispute.created', $this->dispute('needs_response'))->assertOk();

        $this->lock($lease, 'card', false, null)->assertOk();
        $this->assertNull($lease->fresh()->chargeback_locked_method);

        $this->webhook('charge.dispute.closed', $this->dispute('won'))->assertOk();
        $this->assertFalse($lease->fresh()->isMethodLocked('card'));
        $this->assertSame(1, PaymentAuditLog::where('action', 'method.unlocked')->count());
    }
}

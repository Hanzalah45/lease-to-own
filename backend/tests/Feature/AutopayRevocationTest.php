<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\AutopayRevocation;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentAuditLog;
use App\Models\User;
use App\Notifications\AutopayRevocationNotification;
use App\Services\BusinessDays;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * A customer turning AutoPay off (client, Joel, 2026-10-19 checklist, section 5: "disable AutoPay", "revocation window (3 business
 * days)") and the AutoPay log. The authorization asks for written notice at least three business days before the next payment, so
 * AutoPay keeps charging until then and the system switches it off by itself on that day.
 */
class AutopayRevocationTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
        config(['billing.autopay_charging_enabled' => true]);
        // Thursday the 15th, 10:00 in Texas.
        $this->travelTo(Carbon::parse('2026-10-15 15:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'name' => 'Stefany Staff']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function lease(array $overrides = []): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Jane Doe']);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_rev']);

        return LeaseAgreement::factory()->pickedUp()->create(array_merge([
            'customer_id' => $customer->id, 'monthly_rental_payment' => 200, 'sales_tax_rate' => 0, 'autopay_enabled' => true,
            'stripe_bank_payment_method_id' => 'pm_bank', 'stripe_card_payment_method_id' => 'pm_card', 'autopay_primary_method' => 'card',
        ], $overrides));
    }

    private function revoke(LeaseAgreement $lease, array $body = ['confirm' => true], ?User $as = null)
    {
        return $this->withHeaders(['User-Agent' => 'TestPhone/2.0'])->actingAs($as ?? $lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/autopay/revoke", $body);
    }

    private function intent(string $id, string $status): array
    {
        return ['id' => $id, 'object' => 'payment_intent', 'status' => $status];
    }

    // ---- business days ----

    public function test_business_days_skip_weekends_and_the_days_the_banks_are_closed(): void
    {
        $date = fn (string $from, int $n) => BusinessDays::add(Carbon::parse($from), $n)->toDateString();

        $this->assertSame('2026-10-20', $date('2026-10-15', 3), 'Thursday + 3: Friday, Monday, Tuesday.');
        $this->assertSame('2026-10-21', $date('2026-10-16', 3), 'Friday + 3: Monday, Tuesday, Wednesday.');
        $this->assertSame('2026-10-21', $date('2026-10-17', 3), 'Saturday + 3 counts from Monday.');
        $this->assertSame('2026-12-01', $date('2026-11-25', 3), 'Thanksgiving (Thursday the 26th) is not a business day.');
        $this->assertSame('2027-07-07', $date('2027-07-01', 3), 'July 4th falls on a Sunday in 2027 and is observed on Monday the 5th.');
        $this->assertSame('2027-12-27', $date('2027-12-22', 3), 'Christmas on a Saturday is not moved: Friday the 24th is a business day.');
        $this->assertTrue(BusinessDays::isBusinessDay(Carbon::parse('2027-12-24')));
        $this->assertFalse(BusinessDays::isBusinessDay(Carbon::parse('2027-07-05')));
        $this->assertSame('2026-10-12', collect(BusinessDays::holidays(2026))->first(fn ($d) => str_starts_with($d, '2026-10')), 'Columbus Day, the second Monday of October.');
    }

    // ---- the request ----

    public function test_a_customer_asks_to_turn_autopay_off_and_it_keeps_charging_until_the_third_business_day(): void
    {
        Notification::fake();
        $lease = $this->lease();

        $response = $this->revoke($lease)->assertOk();

        $response->assertJsonPath('data.autopay_revocation.effective_on', '2026-10-20')->assertJsonPath('data.can_revoke_autopay', false);
        $row = AutopayRevocation::firstOrFail();
        $this->assertSame('pending', $row->status);
        $this->assertSame('portal', $row->via);
        $this->assertSame($lease->customer_id, $row->user_id);
        $this->assertSame('127.0.0.1', $row->ip_address);
        $this->assertSame('TestPhone/2.0', $row->user_agent);
        $this->assertTrue($lease->fresh()->autopay_enabled, 'Still on: the notice has not run out.');

        $audit = PaymentAuditLog::where('action', 'autopay.revocation_requested')->firstOrFail();
        $this->assertSame('customer', $audit->actor_type);
        $this->assertStringContainsString('Oct 20, 2026', $lease->application->dealerNotes()->firstOrFail()->text);
        Notification::assertSentTo($lease->customer, AutopayRevocationNotification::class);
        Notification::assertSentTo($this->admin, AutopayRevocationNotification::class);
    }

    public function test_the_request_needs_the_customer_to_confirm_and_a_lease_with_autopay_on(): void
    {
        $lease = $this->lease();

        $this->revoke($lease, [])->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->revoke($lease, ['confirm' => false])->assertStatus(422);
        $this->assertSame(0, AutopayRevocation::count());

        $off = $this->lease(['autopay_enabled' => false]);
        $this->revoke($off)->assertStatus(422)->assertJsonPath('message', 'AutoPay is already off for this lease.');

        $ended = $this->lease();
        $ended->forceFill(['ended_at' => now(), 'ended_reason' => 'returned'])->save();
        $this->revoke($ended)->assertStatus(422)->assertJsonPath('message', 'This lease has ended.');

        $this->revoke($lease)->assertOk();
        $this->revoke($lease)->assertStatus(422)->assertJsonPath('message', 'You already asked to turn AutoPay off.');
        $this->assertSame(1, AutopayRevocation::where('lease_agreement_id', $lease->id)->count());

        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->revoke($lease, ['confirm' => true], $other)->assertNotFound();
    }

    public function test_the_customer_can_change_their_mind_before_the_day(): void
    {
        Notification::fake();
        $lease = $this->lease();
        $this->revoke($lease)->assertOk();

        $this->actingAs($lease->customer, 'sanctum')->postJson("/api/customer/lease-agreements/{$lease->id}/autopay/keep")
            ->assertOk()
            ->assertJsonPath('data.autopay_revocation', null)
            ->assertJsonPath('data.can_revoke_autopay', true);

        $this->assertSame('withdrawn', AutopayRevocation::firstOrFail()->status);
        $this->assertNotNull(AutopayRevocation::firstOrFail()->resolved_at);
        $this->assertSame(1, PaymentAuditLog::where('action', 'autopay.revocation_withdrawn')->count());

        // Nothing left to withdraw, and a new request can be made again.
        $this->actingAs($lease->customer, 'sanctum')->postJson("/api/customer/lease-agreements/{$lease->id}/autopay/keep")->assertStatus(422);
        $this->revoke($lease)->assertOk();
    }

    // ---- what the notice does to charging ----

    public function test_autopay_charges_until_the_day_and_then_stops_and_the_morning_job_switches_it_off(): void
    {
        Notification::fake();
        $lease = $this->lease();
        $this->revoke($lease)->assertOk();

        // Monday the 19th: still inside the notice, a payment due is charged as usual.
        $this->travelTo(Carbon::parse('2026-10-19 15:00:00', 'UTC'));
        $due = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 200, 'due_date' => '2026-10-19']);
        $this->stripe->queue($this->intent('pi_mon', 'succeeded'));
        $this->artisan('payments:charge-autopay')->assertSuccessful();
        $this->assertSame(Payment::STATUS_PAID, $due->fresh()->status);

        // Tuesday the 20th: the day has come. Even before the morning job has run nothing is charged.
        $this->travelTo(Carbon::parse('2026-10-20 15:00:00', 'UTC'));
        $next = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 200, 'due_date' => '2026-10-20']);
        $this->artisan('payments:charge-autopay')->assertSuccessful();
        $this->assertCount(1, $this->stripe->requests);
        $this->assertSame(Payment::STATUS_PENDING, $next->fresh()->status);

        $this->artisan('autopay:apply-revocations')->expectsOutputToContain('1 lease')->assertSuccessful();

        $lease->refresh();
        $this->assertFalse($lease->autopay_enabled);
        $row = AutopayRevocation::firstOrFail();
        $this->assertSame('applied', $row->status);
        $audit = PaymentAuditLog::where('action', 'autopay.turned_off')->firstOrFail();
        $this->assertSame('system', $audit->actor_type);
        Notification::assertSentToTimes($lease->customer, AutopayRevocationNotification::class, 2);
        $this->assertStringContainsString('switched off', $lease->application->dealerNotes()->latest('id')->firstOrFail()->text);

        // Once only.
        $this->artisan('autopay:apply-revocations')->expectsOutputToContain('0 lease')->assertSuccessful();
        $this->assertSame(1, PaymentAuditLog::where('action', 'autopay.turned_off')->count());
    }

    public function test_the_morning_job_leaves_a_request_that_has_not_reached_its_day(): void
    {
        $lease = $this->lease();
        $this->revoke($lease)->assertOk();

        $this->artisan('autopay:apply-revocations')->expectsOutputToContain('0 lease')->assertSuccessful();

        $this->assertTrue($lease->fresh()->autopay_enabled);
        $this->assertSame('pending', AutopayRevocation::firstOrFail()->status);
    }

    public function test_it_is_too_late_to_keep_autopay_once_the_day_has_come(): void
    {
        $lease = $this->lease();
        $this->revoke($lease)->assertOk();
        $this->travelTo(Carbon::parse('2026-10-20 15:00:00', 'UTC'));

        $this->actingAs($lease->customer, 'sanctum')->postJson("/api/customer/lease-agreements/{$lease->id}/autopay/keep")
            ->assertStatus(422)
            ->assertJsonPath('message', 'AutoPay is being switched off today and cannot be kept.');
    }

    // ---- staff ----

    public function test_staff_record_a_written_request_and_the_same_notice_runs(): void
    {
        $lease = $this->lease();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/autopay/revocation", ['customer_asked_in_writing' => true])
            ->assertOk()
            ->assertJsonPath('data.effective_on', '2026-10-20');

        $row = AutopayRevocation::firstOrFail();
        $this->assertSame('staff', $row->via);
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertStringContainsString('Stefany Staff recorded that the customer', $lease->application->dealerNotes()->firstOrFail()->text);

        $view = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/lease-agreements/{$lease->id}/autopay-methods")->assertOk();
        $this->assertSame('2026-10-20', $view->json('data.autopay_revocation.effective_on'));
        $this->assertSame('staff', $view->json('data.autopay_revocation.via'));
    }

    public function test_the_staff_request_needs_the_tick_and_payment_tracking(): void
    {
        $lease = $this->lease();
        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);
        $url = "/api/admin/lease-agreements/{$lease->id}/autopay/revocation";

        $this->actingAs($this->admin, 'sanctum')->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('customer_asked_in_writing');
        $this->actingAs($reviewer, 'sanctum')->postJson($url, ['customer_asked_in_writing' => true])->assertForbidden();
        $this->actingAs($lease->customer, 'sanctum')->postJson($url, ['customer_asked_in_writing' => true])->assertForbidden();
        $this->assertSame(0, AutopayRevocation::count());
    }

    // ---- the AutoPay log ----

    private function attempt(Payment $payment, array $extra = []): PaymentAttempt
    {
        return PaymentAttempt::create(array_merge([
            'payment_id' => $payment->id, 'attempt_no' => 1, 'round' => 1, 'method' => 'card', 'amount_cents' => 20600, 'fee_cents' => 600,
            'stripe_payment_method_id' => 'pm_card', 'idempotency_key' => "autopay-{$payment->id}-a1-card", 'status' => 'succeeded',
        ], $extra));
    }

    public function test_the_autopay_log_lists_every_try_with_its_outcome_and_reason_and_filters(): void
    {
        $lease = $this->lease();
        $payment = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 200, 'due_date' => '2026-10-15']);
        $this->attempt($payment);
        $this->attempt($payment, ['attempt_no' => 2, 'method' => 'bank', 'amount_cents' => 20000, 'fee_cents' => 0, 'status' => 'failed', 'failure_code' => 'account_closed', 'failure_message' => 'The bank account is closed.', 'idempotency_key' => "autopay-{$payment->id}-a2-bank"]);
        $this->attempt($payment, ['attempt_no' => 3, 'idempotency_key' => "customer-pay-{$payment->id}-a3-card", 'status' => 'processing']);

        $all = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/autopay-log')->assertOk();
        $this->assertSame(3, $all->json('total'));
        $this->assertSame(['processing', 'failed', 'succeeded'], array_column($all->json('data'), 'status'));
        $failed = $all->json('data.1');
        $this->assertSame('account_closed', $failed['failure_code']);
        $this->assertSame('The bank account is closed.', $failed['failure_message']);
        $this->assertEqualsWithDelta(200.0, $failed['amount'], 0.001);
        $this->assertSame('Jane Doe', $failed['customer']);
        $this->assertSame('2026-10-15', $failed['due_date']);
        $this->assertSame('autopay', $failed['started_by']);
        $this->assertSame('customer', $all->json('data.0.started_by'));

        $this->assertSame(1, $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/autopay-log?status=failed')->json('total'));
        $this->assertSame(1, $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/autopay-log?method=bank')->json('total'));
        $this->assertSame(1, $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/autopay-log?started_by=customer')->json('total'));
        $this->assertSame(2, $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/autopay-log?started_by=autopay')->json('total'));
        $this->assertSame(3, $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/autopay-log?lease_agreement_id={$lease->id}")->json('total'));
        $this->assertSame(0, $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/autopay-log?lease_agreement_id=99999')->json('total'));
        $this->assertSame(3, $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/autopay-log?from=2026-10-15&to=2026-10-15')->json('total'));
        $this->assertSame(0, $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/autopay-log?from=2026-10-16')->json('total'));
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/autopay-log?status=nope')->assertStatus(422);
    }

    public function test_the_autopay_log_is_for_staff_with_payment_tracking_only(): void
    {
        $lease = $this->lease();
        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);

        $this->actingAs($lease->customer, 'sanctum')->getJson('/api/admin/autopay-log')->assertForbidden();
        $this->actingAs($reviewer, 'sanctum')->getJson('/api/admin/autopay-log')->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Contract;
use App\Models\CustomerProfile;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Notifications\AutopayNeedsReviewNotification;
use App\Notifications\RequestContractSignatureNotification;
use App\Services\LeaseEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Stripe\ApiRequestor;
use Stripe\Exception\InvalidRequestException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Customers who picked up equipment before the billing-cycle system existed
 * (client, Joel, 2026-10-06): each signs the new contract, chooses a billing
 * day, and moves onto the new schedule from their real pickup date. Their old
 * rows were anchored to the creation date and never prorated.
 */
class ExistingCustomerResignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 1pm Central on Oct 6, 2026 (the same calendar date in UTC and Texas).
        $this->travelTo(Carbon::parse('2026-10-06 18:00:00', 'UTC'));
    }

    /** A picked-up lease on the OLD schedule: the first row (due a month after pickup) paid at pickup, then monthly rows. */
    private function legacyLease(string $pickup = '2026-09-15', array $leaseOverrides = [], string $customerStatus = 'active'): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => $customerStatus]);
        $lease = LeaseAgreement::factory()->pickedUp()->create(array_merge([
            'customer_id' => $customer->id,
            'term_months' => 12,
            'start_date' => $pickup,
            'monthly_rental_payment' => 300,
            'sales_tax_rate' => 0,
            'ldw_selected' => false,
            'billing_cycle' => '15th',
            'autopay_enabled' => true,
        ], $leaseOverrides));
        // The application belongs to the same customer, as it does in real data.
        $lease->application->update(['customer_id' => $customer->id]);

        $start = Carbon::parse($pickup);
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_RENTAL,
            'amount' => 300,
            'due_date' => $start->copy()->addMonthNoOverflow()->toDateString(),
            'status' => Payment::STATUS_PAID,
            'paid_date' => $pickup,
        ]);
        for ($m = 2; $m <= 12; $m++) {
            Payment::factory()->create([
                'lease_agreement_id' => $lease->id,
                'type' => Payment::TYPE_RENTAL,
                'amount' => 300,
                'due_date' => $start->copy()->addMonthsNoOverflow($m)->toDateString(),
                'status' => Payment::STATUS_PENDING,
            ]);
        }

        return $lease->fresh();
    }

    private function pending(LeaseAgreement $lease)
    {
        return $lease->payments()->where('type', Payment::TYPE_RENTAL)->where('status', Payment::STATUS_PENDING)->orderBy('due_date')->get();
    }

    // ---- LeaseEngine::rebuildSchedule ----

    public function test_a_dry_run_shows_the_new_schedule_without_changing_anything(): void
    {
        $lease = $this->legacyLease();
        $before = $this->pending($lease)->pluck('due_date')->map->toDateString()->all();

        $result = LeaseEngine::rebuildSchedule($lease, null, apply: false);

        $this->assertTrue($result['changed']);
        $this->assertSame('2026-10-15', $result['next_due']);
        $this->assertSame(300.0, $result['next_amount']);
        $this->assertSame('2026-11-15', $result['old_next_due']);
        $this->assertSame($before, $this->pending($lease)->pluck('due_date')->map->toDateString()->all());
    }

    public function test_applying_puts_the_lease_on_the_new_schedule_from_its_pickup_date(): void
    {
        $lease = $this->legacyLease();

        LeaseEngine::rebuildSchedule($lease);

        $pending = $this->pending($lease);
        // 11 payments remain after the pickup payment, the next one on the 15th: 30 days after pickup, so a full month.
        $this->assertCount(11, $pending);
        $this->assertSame('2026-10-15', $pending->first()->due_date->toDateString());
        $this->assertEqualsWithDelta(300.0, (float) $pending->first()->amount, 0.001);
        $this->assertSame('2027-08-15', $pending->last()->due_date->toDateString());

        // The pickup payment stays paid, dated to the real pickup day.
        $paid = $lease->payments()->where('status', Payment::STATUS_PAID)->sole();
        $this->assertSame('2026-09-15', $paid->due_date->toDateString());
        $this->assertEqualsWithDelta(300.0, (float) $paid->amount, 0.001);

        $lease->refresh();
        $this->assertSame('2026-09-15', $lease->start_date->toDateString());
        $this->assertSame('2026-10-15', $lease->renewal_date->toDateString());
        $this->assertSame('2027-08-15', $lease->equipmentUnit->expected_return_or_ownership_date->toDateString());
        $this->assertEqualsWithDelta(300.0, (float) $lease->rental_payments_paid_to_date, 0.001);
    }

    public function test_the_second_payment_is_prorated_when_the_next_billing_day_is_under_thirty_days_away(): void
    {
        $lease = $this->legacyLease('2026-09-16');

        LeaseEngine::rebuildSchedule($lease);

        // 29 days from pickup to the 15th: 300 / 30 x 29.
        $this->assertEqualsWithDelta(290.0, (float) $this->pending($lease)->first()->amount, 0.001);
        $this->assertSame('2026-10-15', $this->pending($lease)->first()->due_date->toDateString());
        $this->assertEqualsWithDelta(300.0, (float) $this->pending($lease)[1]->amount, 0.001);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $lease = $this->legacyLease();
        LeaseEngine::rebuildSchedule($lease);
        $ids = $this->pending($lease)->pluck('id')->all();

        $again = LeaseEngine::rebuildSchedule($lease);

        $this->assertFalse($again['changed']);
        $this->assertSame($ids, $this->pending($lease)->pluck('id')->all());
    }

    public function test_it_refuses_anything_that_is_not_untouched(): void
    {
        $cases = [
            'two paid payments' => function (LeaseAgreement $l) {
                $this->pending($l)->first()->update(['status' => Payment::STATUS_PAID, 'paid_date' => '2026-10-01']);
            },
            'an in flight charge' => function (LeaseAgreement $l) {
                $this->pending($l)->first()->update(['stripe_payment_intent_id' => 'pi_inflight']);
            },
            'a failed payment' => function (LeaseAgreement $l) {
                $this->pending($l)->first()->update(['status' => Payment::STATUS_FAILED]);
            },
            'an automatic charge attempt' => function (LeaseAgreement $l) {
                PaymentAttempt::create([
                    'payment_id' => $this->pending($l)->first()->id, 'attempt_no' => 1, 'round' => 1, 'method' => 'card',
                    'amount_cents' => 100, 'fee_cents' => 0, 'stripe_payment_method_id' => 'pm_x', 'idempotency_key' => 'k-'.$l->id,
                ]);
            },
            'a late fee' => function (LeaseAgreement $l) {
                Payment::factory()->create([
                    'lease_agreement_id' => $l->id, 'type' => Payment::TYPE_LATE_FEE,
                    'late_fee_for_payment_id' => $this->pending($l)->first()->id, 'amount' => 30,
                ]);
            },
        ];

        foreach ($cases as $label => $mutate) {
            $lease = $this->legacyLease();
            $mutate($lease);
            $before = $lease->payments()->orderBy('id')->pluck('due_date', 'id')->map->toDateString()->all();

            try {
                LeaseEngine::rebuildSchedule($lease);
                $this->fail("Expected a refusal for: {$label}");
            } catch (HttpException) {
                // expected
            }

            $this->assertSame($before, $lease->payments()->orderBy('id')->pluck('due_date', 'id')->map->toDateString()->all(), $label);
        }
    }

    public function test_it_refuses_a_lease_that_has_not_been_picked_up_or_has_no_billing_cycle(): void
    {
        $notPickedUp = $this->legacyLease();
        $notPickedUp->application->update(['status' => Application::STATUS_WAITING_DEPOSIT]);
        $noCycle = $this->legacyLease('2026-09-15', ['billing_cycle' => null]);

        foreach ([$notPickedUp, $noCycle] as $lease) {
            try {
                LeaseEngine::rebuildSchedule($lease->fresh());
                $this->fail('Expected a refusal.');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
            // Nothing was rewritten.
            $this->assertSame('2026-11-15', $this->pending($lease)->first()->due_date->toDateString());
        }
    }

    // ---- the command ----

    public function test_the_command_defaults_to_a_dry_run_and_applies_only_when_asked(): void
    {
        $lease = $this->legacyLease();

        $this->artisan('lease:rebuild-schedule', ['--lease' => [$lease->id]])
            ->expectsOutputToContain('would rebuild. Pickup 2026-09-15, 15th cycle. Next payment $300.00 on 2026-10-15 (was $300.00 on 2026-11-15)')
            ->assertSuccessful();
        $this->assertSame('2026-11-15', $this->pending($lease)->first()->due_date->toDateString());

        $this->artisan('lease:rebuild-schedule', ['--lease' => [$lease->id], '--apply' => true])
            ->expectsOutputToContain('REBUILT')
            ->assertSuccessful();
        $this->assertSame('2026-10-15', $this->pending($lease)->first()->due_date->toDateString());
    }

    public function test_all_picks_up_only_finished_leases_and_reports_refusals(): void
    {
        $good = $this->legacyLease();
        $stuck = $this->legacyLease('2026-09-16');
        $stuck->payments()->where('status', Payment::STATUS_PENDING)->first()->update(['stripe_payment_intent_id' => 'pi_x']);
        $this->legacyLease()->application->update(['status' => Application::STATUS_WAITING_DEPOSIT]);

        $this->artisan('lease:rebuild-schedule', ['--all' => true, '--apply' => true])
            ->expectsOutputToContain('Changed: 1, already current: 0, refused: 1')
            ->assertSuccessful();

        $this->assertSame('2026-10-15', $this->pending($good)->first()->due_date->toDateString());
    }

    // ---- re-signing through the real endpoints ----

    private function voidedLease(string $pickup = '2026-09-15', string $customerStatus = 'active'): LeaseAgreement
    {
        $lease = $this->legacyLease($pickup, [], $customerStatus);
        Contract::factory()->create([
            'lease_agreement_id' => $lease->id,
            'signer_user_id' => $lease->customer_id,
            'voided_at' => now(),
            'void_reason' => 'Updated agreement with new billing dates and AutoPay terms.',
        ]);

        return $lease;
    }

    public function test_the_signing_page_shows_the_real_next_payment_for_a_picked_up_customer(): void
    {
        $lease = $this->voidedLease('2026-09-16');

        $data = $this->actingAs($lease->customer, 'sanctum')
            ->getJson("/api/customer/lease-agreements/{$lease->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-09-16', $data['billing_preview_pickup_date']);
        $this->assertSame('2026-10-15', $data['billing_preview']['15th']['second_payment_date']);
        $this->assertTrue($data['billing_preview']['15th']['second_payment_prorated']);
        $this->assertEqualsWithDelta(290.0, $data['billing_preview']['15th']['second_payment_amount'], 0.001);
    }

    public function test_a_lease_not_yet_picked_up_still_previews_as_if_picked_up_today(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => Application::STATUS_WAITING_DEPOSIT]);
        $lease = LeaseAgreement::factory()->create(['application_id' => $application->id, 'customer_id' => $customer->id, 'monthly_rental_payment' => 300, 'sales_tax_rate' => 0]);

        $data = $this->actingAs($customer, 'sanctum')->getJson("/api/customer/lease-agreements/{$lease->id}")->assertOk()->json('data');

        $this->assertNull($data['billing_preview_pickup_date']);
        $this->assertSame('2026-10-06', $data['billing_preview']['15th']['first_payment_date']);
    }

    public function test_signing_the_new_contract_rebuilds_the_schedule_and_starts_no_deposit_hold(): void
    {
        Notification::fake();
        $lease = $this->voidedLease();

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson('/api/customer/contracts', ['lease_agreement_id' => $lease->id, 'signer_name' => 'Pat Customer', 'billing_cycle' => '15th'])
            ->assertCreated();

        $this->assertSame('2026-10-15', $this->pending($lease)->first()->due_date->toDateString());
        $this->assertCount(11, $this->pending($lease));
        $application = $lease->application->fresh();
        $this->assertTrue($application->signature_received);
        // Picked up already: nothing to hold, and the forfeiture job must never see a new hold date.
        $this->assertNull($application->deposit_hold_expires_at);
        $this->assertSame(Application::STATUS_FINISHED, $application->status);
    }

    public function test_a_billing_day_that_has_already_passed_cannot_be_chosen(): void
    {
        $lease = $this->voidedLease();

        // Pickup 9/15 on the 1st: the next 1st is 10/1, already behind today (10/6).
        $this->actingAs($lease->customer, 'sanctum')
            ->postJson('/api/customer/contracts', ['lease_agreement_id' => $lease->id, 'signer_name' => 'Pat Customer', 'billing_cycle' => '1st'])
            ->assertStatus(422);

        $this->assertNull($lease->fresh()->contract);
        $this->assertSame('15th', $lease->fresh()->billing_cycle);
        $this->assertSame('2026-11-15', $this->pending($lease)->first()->due_date->toDateString());
    }

    public function test_staff_are_told_when_the_schedule_could_not_be_rebuilt_but_the_signature_still_counts(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $lease = $this->voidedLease();
        $this->pending($lease)->first()->update(['stripe_payment_intent_id' => 'pi_inflight']);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson('/api/customer/contracts', ['lease_agreement_id' => $lease->id, 'signer_name' => 'Pat Customer', 'billing_cycle' => '15th'])
            ->assertCreated();

        $this->assertNotNull($lease->fresh()->contract);
        $this->assertSame('2026-11-15', $this->pending($lease)->first()->due_date->toDateString());
        Notification::assertSentTo($admin, AutopayNeedsReviewNotification::class);
    }

    public function test_staff_can_send_the_signing_link_to_a_picked_up_customer_without_a_login(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $lease = $this->voidedLease('2026-09-15', 'pending');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/applications/{$lease->application_id}/resend-signing-link")
            ->assertOk();

        Notification::assertSentTo($lease->customer, RequestContractSignatureNotification::class);
    }

    public function test_the_signing_link_is_still_refused_for_an_application_that_is_neither_waiting_nor_picked_up(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $lease = $this->voidedLease('2026-09-15', 'pending');
        $lease->application->update(['status' => Application::STATUS_WAITING_REVIEW]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/applications/{$lease->application_id}/resend-signing-link")
            ->assertStatus(422);
    }

    // ---- the switch from Stripe test keys to live keys ----

    private function fakeStripe(): FakeStripeHttpClient
    {
        $fake = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($fake);

        return $fake;
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function missing(string $what): array
    {
        return ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => "No such {$what}"]];
    }

    public function test_references_missing_in_the_new_stripe_mode_are_listed_then_cleared(): void
    {
        config(['services.stripe.secret' => 'sk_live_for_specs']);
        $fake = $this->fakeStripe();
        $lease = $this->legacyLease('2026-09-15', ['stripe_bank_payment_method_id' => 'pm_test_bank', 'stripe_card_payment_method_id' => 'pm_test_card', 'autopay_primary_method' => 'ach']);
        $profile = CustomerProfile::firstOrCreate(['user_id' => $lease->customer_id]);
        $profile->update(['stripe_customer_id' => 'cus_test_old']);

        // Customer, then bank PM, then card PM: none of them exist in live mode.
        $fake->queue($this->missing('customer'), 404)->queue($this->missing('PaymentMethod'), 404)->queue($this->missing('PaymentMethod'), 404);
        $this->artisan('stripe:prune-missing-references')
            ->expectsOutputToContain('LIVE key')
            ->expectsOutputToContain('Dry run, nothing was changed. Would clear 3 reference(s).')
            ->assertSuccessful();
        $this->assertSame('cus_test_old', $profile->fresh()->stripe_customer_id);
        $this->assertSame('pm_test_bank', $lease->fresh()->stripe_bank_payment_method_id);

        $fake->queue($this->missing('customer'), 404)->queue($this->missing('PaymentMethod'), 404)->queue($this->missing('PaymentMethod'), 404);
        $this->artisan('stripe:prune-missing-references', ['--apply' => true])
            ->expectsOutputToContain('Cleared 3 reference(s).')
            ->assertSuccessful();

        $this->assertNull($profile->fresh()->stripe_customer_id);
        $lease->refresh();
        $this->assertNull($lease->stripe_bank_payment_method_id);
        $this->assertNull($lease->stripe_card_payment_method_id);
        $this->assertNull($lease->autopay_primary_method);
    }

    public function test_references_that_exist_in_the_current_mode_are_never_cleared(): void
    {
        config(['services.stripe.secret' => 'sk_test_for_specs']);
        $fake = $this->fakeStripe();
        $lease = $this->legacyLease('2026-09-15', ['stripe_card_payment_method_id' => 'pm_ok', 'autopay_primary_method' => 'card']);
        CustomerProfile::firstOrCreate(['user_id' => $lease->customer_id])->update(['stripe_customer_id' => 'cus_ok']);

        $fake->queue(['id' => 'cus_ok', 'object' => 'customer'])->queue(['id' => 'pm_ok', 'object' => 'payment_method']);
        $this->artisan('stripe:prune-missing-references', ['--apply' => true])
            ->expectsOutputToContain('Cleared 0 reference(s).')
            ->assertSuccessful();

        $this->assertSame('pm_ok', $lease->fresh()->stripe_card_payment_method_id);
        $this->assertSame('card', $lease->fresh()->autopay_primary_method);
    }

    public function test_a_deleted_customer_counts_as_missing_and_other_stripe_errors_are_not_swallowed(): void
    {
        config(['services.stripe.secret' => 'sk_live_for_specs']);
        $fake = $this->fakeStripe();
        $lease = $this->legacyLease();
        $profile = CustomerProfile::firstOrCreate(['user_id' => $lease->customer_id]);
        $profile->update(['stripe_customer_id' => 'cus_gone']);

        $fake->queue(['id' => 'cus_gone', 'object' => 'customer', 'deleted' => true]);
        $this->artisan('stripe:prune-missing-references', ['--apply' => true])->expectsOutputToContain('Cleared 1 reference(s).')->assertSuccessful();
        $this->assertNull($profile->fresh()->stripe_customer_id);

        // An authentication failure must stop the run rather than look like "missing".
        $profile->update(['stripe_customer_id' => 'cus_other']);
        $fake->queue(['error' => ['type' => 'invalid_request_error', 'code' => 'api_key_expired', 'message' => 'Expired']], 400);
        $this->expectException(InvalidRequestException::class);
        $this->artisan('stripe:prune-missing-references', ['--apply' => true]);
    }
}

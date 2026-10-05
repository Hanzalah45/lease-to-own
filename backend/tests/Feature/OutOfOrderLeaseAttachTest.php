<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\ActivateAccountNotification;
use App\Services\ContractSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Real gap found 2026-09-21 on production application #5: a guest
 * application reached "finished" (contract signed, unit delivered) with ZERO
 * payments. Nothing requires a lease to exist for the waiting_approval /
 * in_verification / waiting_deposit transitions, and the payment schedule was
 * only ever generated at the waiting_deposit transition itself — so a lease
 * attached afterward never got one, "Mark Delivered & Paid" then found no
 * payment to mark, and the guest's account-setup email (which fires on the
 * first payment landing) was never sent.
 *
 * Since 2026-10-05 the monthly schedule is built when the equipment is picked
 * up (LeaseEngine::startLease), not at any earlier status change, so a lease
 * attached out of order needs no special catch-up: pickup is the one place
 * the schedule and the first payment are created.
 */
class OutOfOrderLeaseAttachTest extends TestCase
{
    use RefreshDatabase;

    private function guestAdvancedWithoutALease(User $admin): Application
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending']);
        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => Application::STATUS_WAITING_REVIEW,
        ]);

        foreach ([Application::STATUS_WAITING_APPROVAL, Application::STATUS_IN_VERIFICATION, Application::STATUS_WAITING_DEPOSIT] as $status) {
            $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", ['status' => $status])->assertOk();
        }

        return $application;
    }

    private function attachLease(User $admin, Application $application): void
    {
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/lease", [
            'make' => 'Worldlawn',
            'model' => 'Zero-Turn 52',
            'cash_price' => 5000,
            'term_months' => 36,
            'monthly_rental' => 200,
        ])->assertOk();
    }

    private function signViaGuestLink(Application $application): void
    {
        $lease = LeaseAgreement::where('application_id', $application->id)->firstOrFail();
        parse_str(parse_url(ContractSigner::urlFor($application->customer, $lease), PHP_URL_QUERY), $params);
        $this->postJson('/api/contracts/verify-sign', [...$params, 'signer_name' => 'Guest Signer', 'billing_cycle' => '1st'])->assertCreated();
    }

    public function test_attaching_a_lease_after_waiting_deposit_builds_no_schedule_before_pickup(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $application = $this->guestAdvancedWithoutALease($admin);

        $this->attachLease($admin, $application);

        $lease = LeaseAgreement::where('application_id', $application->id)->firstOrFail();
        $this->assertSame(0, $lease->payments()->count());
    }

    public function test_the_full_out_of_order_path_builds_the_schedule_at_pickup_and_activates_the_account(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $application = $this->guestAdvancedWithoutALease($admin);

        $this->attachLease($admin, $application);
        $this->signViaGuestLink($application);

        // Not under test here (AutoPay payment methods are covered by
        // AutopayPaymentMethodsTest).
        foreach ([Application::STATUS_WAITING_DELIVERY, Application::STATUS_FINISHED] as $status) {
            $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", ['status' => $status, 'override_payment_methods_check' => true])->assertOk();
        }

        $lease = LeaseAgreement::where('application_id', $application->id)->firstOrFail();
        $this->assertSame(36, $lease->payments()->where('type', Payment::TYPE_RENTAL)->count());
        $this->assertSame(1, $lease->payments()->where('status', Payment::STATUS_PAID)->count());
        Notification::assertSentTo($application->customer, ActivateAccountNotification::class);
    }

    public function test_delivery_is_refused_while_the_lease_has_no_billing_cycle(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $application = $this->guestAdvancedWithoutALease($admin);
        $this->attachLease($admin, $application);
        $this->signViaGuestLink($application);

        // An older signed lease that never got a cycle.
        $lease = LeaseAgreement::where('application_id', $application->id)->firstOrFail();
        $lease->update(['billing_cycle' => null]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", ['status' => Application::STATUS_WAITING_DELIVERY, 'override_payment_methods_check' => true])->assertOk();

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", ['status' => Application::STATUS_FINISHED])->assertStatus(422);
        $this->assertSame(Application::STATUS_WAITING_DELIVERY, $application->fresh()->status);
        $this->assertSame(0, $lease->payments()->count());

        // The admin supplies the cycle at delivery.
        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", ['status' => Application::STATUS_FINISHED, 'billing_cycle' => '15th'])->assertOk();
        $this->assertSame('15th', $lease->fresh()->billing_cycle);
        $this->assertSame(36, $lease->payments()->count());
    }
}

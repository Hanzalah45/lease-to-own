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
 * first payment landing) was never sent. The customer was left with no
 * schedule, no late-fee/autopay tracking, and no way to ever set a password.
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

    private function signViaGuestLink(Application $application): void
    {
        $lease = LeaseAgreement::where('application_id', $application->id)->firstOrFail();
        parse_str(parse_url(ContractSigner::urlFor($application->customer, $lease), PHP_URL_QUERY), $params);
        $this->postJson('/api/contracts/verify-sign', [...$params, 'signer_name' => 'Guest Signer'])->assertCreated();
    }

    public function test_attaching_a_lease_after_waiting_deposit_generates_the_payment_schedule(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $application = $this->guestAdvancedWithoutALease($admin);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/lease", [
            'make' => 'Worldlawn',
            'model' => 'Zero-Turn 52',
            'cash_price' => 5000,
            'term_months' => 36,
            'monthly_rental' => 200,
        ])->assertOk();

        $lease = LeaseAgreement::where('application_id', $application->id)->firstOrFail();
        $this->assertSame(36, $lease->payments()->count());
    }

    public function test_the_full_out_of_order_path_records_the_first_payment_and_activates_the_account(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $application = $this->guestAdvancedWithoutALease($admin);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/lease", [
            'make' => 'Worldlawn',
            'model' => 'Zero-Turn 52',
            'cash_price' => 5000,
            'term_months' => 36,
            'monthly_rental' => 200,
        ])->assertOk();

        $this->signViaGuestLink($application);

        foreach ([Application::STATUS_WAITING_DELIVERY, Application::STATUS_FINISHED] as $status) {
            $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", ['status' => $status])->assertOk();
        }

        $lease = LeaseAgreement::where('application_id', $application->id)->firstOrFail();
        $this->assertSame(1, $lease->payments()->where('status', Payment::STATUS_PAID)->count());
        Notification::assertSentTo($application->customer, ActivateAccountNotification::class);
    }

    public function test_finishing_still_records_the_first_payment_when_no_schedule_exists_at_all(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $application = $this->guestAdvancedWithoutALease($admin);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/lease", [
            'make' => 'Worldlawn',
            'model' => 'Zero-Turn 52',
            'cash_price' => 5000,
            'term_months' => 36,
            'monthly_rental' => 200,
        ])->assertOk();
        $this->signViaGuestLink($application);

        // Reproduces production's state exactly: a signed lease with no
        // schedule, however it got that way.
        $lease = LeaseAgreement::where('application_id', $application->id)->firstOrFail();
        $lease->payments()->delete();
        $this->assertSame(0, $lease->payments()->count());

        foreach ([Application::STATUS_WAITING_DELIVERY, Application::STATUS_FINISHED] as $status) {
            $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", ['status' => $status])->assertOk();
        }

        $this->assertSame(36, $lease->payments()->count());
        $this->assertSame(1, $lease->payments()->where('status', Payment::STATUS_PAID)->count());
        Notification::assertSentTo($application->customer, ActivateAccountNotification::class);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\User;
use App\Notifications\ApplicationStatusChangedNotification;
use App\Notifications\DepositForfeitedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Client, 2026-09-05: the security deposit holds the unit for 30 days from
 * the moment the customer signs the contract (see deposit_hold_expires_at,
 * set in Customer\ContractController::store() / PublicContractController).
 * If the unit hasn't been picked up (application never reaches "finished")
 * by then, the deposit is forfeited — deposits:forfeit-expired-holds runs
 * daily and declines the application.
 */
class DepositForfeitureTest extends TestCase
{
    use RefreshDatabase;

    private function applicationWithExpiredHold(
        string $status = Application::STATUS_WAITING_DEPOSIT,
        ?string $expiresAt = null,
    ): Application {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => $status,
            'signature_received' => true,
            'deposit_hold_expires_at' => $expiresAt ?? now()->subDay(),
        ]);
        LeaseAgreement::factory()->create(['application_id' => $application->id, 'customer_id' => $customer->id]);

        return $application;
    }

    public function test_an_expired_unpicked_up_hold_is_forfeited(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $application = $this->applicationWithExpiredHold();

        $this->artisan('deposits:forfeit-expired-holds')->assertSuccessful();

        $application = $application->fresh();
        $this->assertSame(Application::STATUS_DECLINED, $application->status);
        $this->assertNotNull($application->deposit_forfeited_at);
        $this->assertStringContainsString('forfeited', $application->status_notes);

        Notification::assertSentTo($application->customer, ApplicationStatusChangedNotification::class);
        Notification::assertSentTo($admin, DepositForfeitedNotification::class);
    }

    public function test_a_hold_still_within_its_30_days_is_left_alone(): void
    {
        Notification::fake();
        $application = $this->applicationWithExpiredHold(expiresAt: now()->addDays(5));

        $this->artisan('deposits:forfeit-expired-holds')->assertSuccessful();

        $application = $application->fresh();
        $this->assertSame(Application::STATUS_WAITING_DEPOSIT, $application->status);
        $this->assertNull($application->deposit_forfeited_at);
        Notification::assertNothingSent();
    }

    public function test_an_application_that_already_reached_finished_is_not_forfeited(): void
    {
        Notification::fake();
        $application = $this->applicationWithExpiredHold(status: Application::STATUS_FINISHED);

        $this->artisan('deposits:forfeit-expired-holds')->assertSuccessful();

        $application = $application->fresh();
        $this->assertSame(Application::STATUS_FINISHED, $application->status);
        $this->assertNull($application->deposit_forfeited_at);
        Notification::assertNothingSent();
    }

    public function test_an_already_forfeited_application_is_not_forfeited_twice(): void
    {
        Notification::fake();
        $application = $this->applicationWithExpiredHold();
        $application->update(['deposit_forfeited_at' => now()->subHour(), 'status' => Application::STATUS_DECLINED]);

        $this->artisan('deposits:forfeit-expired-holds')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_an_application_with_no_hold_set_is_left_alone(): void
    {
        Notification::fake();
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => Application::STATUS_WAITING_DEPOSIT,
            'deposit_hold_expires_at' => null,
        ]);

        $this->artisan('deposits:forfeit-expired-holds')->assertSuccessful();

        $this->assertSame(Application::STATUS_WAITING_DEPOSIT, $application->fresh()->status);
        Notification::assertNothingSent();
    }

    /**
     * Real gap found this session: an already-signed application that gets
     * manually declined for some unrelated reason (not a forfeiture) and is
     * later reopened (declined -> waiting_review is a legal transition)
     * would otherwise carry its now-in-the-past deposit_hold_expires_at back
     * into the pipeline. Since a lease can't be re-signed once signed, that
     * stale timestamp would get caught by the very next day's forfeiture run
     * and wrongly cancel a legitimately reopened application. Manually
     * declining must clear the hold so a reopened application starts clean.
     */
    public function test_manually_declining_a_signed_application_clears_its_deposit_hold(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $application = $this->applicationWithExpiredHold(expiresAt: now()->addDays(20));

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_DECLINED,
            'status_notes' => 'Unrelated reason — customer changed their mind.',
        ])->assertOk();

        $application = $application->fresh();
        $this->assertSame(Application::STATUS_DECLINED, $application->status);
        $this->assertNull($application->deposit_hold_expires_at);
        $this->assertNull($application->deposit_forfeited_at);

        // Reopen it (a legal transition) and confirm the next day's job
        // leaves it alone — no stale hold left to wrongly trip it.
        $application->update(['status' => Application::STATUS_WAITING_DEPOSIT]);
        Notification::fake();
        $this->artisan('deposits:forfeit-expired-holds')->assertSuccessful();

        $this->assertSame(Application::STATUS_WAITING_DEPOSIT, $application->fresh()->status);
        Notification::assertNothingSent();
    }
}

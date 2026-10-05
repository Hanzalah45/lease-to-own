<?php

namespace Tests\Feature;

use App\Models\LeaseAgreement;
use App\Notifications\LeaseRenewalProcessedNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers a gap found by this session's audit: renewal_date was set once at
 * signing and never touched again — nothing advanced it or told the
 * customer their monthly payment/EPO amount going forward. Since 2026-10-05
 * it only applies to leases already picked up, and advances from the
 * existing renewal_date so it stays on the billing cycle (the 1st or 15th).
 */
class LeaseRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-16 18:00:00', 'UTC'));
    }

    public function test_a_due_lease_is_renewed_and_the_customer_is_notified(): void
    {
        Notification::fake();

        $lease = LeaseAgreement::factory()->pickedUp()->create([
            'renewal_date' => '2026-10-15',
            'ownership_status' => LeaseAgreement::OWNERSHIP_LEASING,
        ]);

        $this->artisan('lease:process-renewals')->assertSuccessful();

        // One month on from the existing date (still the 15th), not from "today".
        $this->assertSame('2026-11-15', $lease->fresh()->renewal_date->toDateString());

        Notification::assertSentTo($lease->customer, LeaseRenewalProcessedNotification::class);
    }

    public function test_a_renewal_that_was_missed_for_several_months_catches_up_in_one_run(): void
    {
        Notification::fake();

        $lease = LeaseAgreement::factory()->pickedUp()->create([
            'renewal_date' => '2026-07-15',
            'ownership_status' => LeaseAgreement::OWNERSHIP_LEASING,
        ]);

        $this->artisan('lease:process-renewals')->assertSuccessful();

        $this->assertSame('2026-11-15', $lease->fresh()->renewal_date->toDateString());
        Notification::assertSentToTimes($lease->customer, LeaseRenewalProcessedNotification::class, 1);
    }

    public function test_a_lease_that_has_not_been_picked_up_is_not_renewed(): void
    {
        Notification::fake();

        $lease = LeaseAgreement::factory()->create([
            'renewal_date' => '2026-10-15',
            'ownership_status' => LeaseAgreement::OWNERSHIP_LEASING,
        ]);

        $this->artisan('lease:process-renewals')->assertSuccessful();

        $this->assertSame('2026-10-15', $lease->fresh()->renewal_date->toDateString());
        Notification::assertNotSentTo($lease->customer, LeaseRenewalProcessedNotification::class);
    }

    public function test_a_fully_owned_lease_is_not_renewed(): void
    {
        Notification::fake();

        $lease = LeaseAgreement::factory()->pickedUp()->create([
            'renewal_date' => '2026-10-15',
            'ownership_status' => LeaseAgreement::OWNERSHIP_OWNED,
        ]);

        $this->artisan('lease:process-renewals')->assertSuccessful();

        $this->assertSame('2026-10-15', $lease->fresh()->renewal_date->toDateString());
        Notification::assertNotSentTo($lease->customer, LeaseRenewalProcessedNotification::class);
    }

    public function test_a_lease_not_yet_due_is_left_alone(): void
    {
        Notification::fake();

        $lease = LeaseAgreement::factory()->pickedUp()->create([
            'renewal_date' => '2026-10-23',
            'ownership_status' => LeaseAgreement::OWNERSHIP_LEASING,
        ]);

        $this->artisan('lease:process-renewals')->assertSuccessful();

        $this->assertSame('2026-10-23', $lease->fresh()->renewal_date->toDateString());
        Notification::assertNotSentTo($lease->customer, LeaseRenewalProcessedNotification::class);
    }
}

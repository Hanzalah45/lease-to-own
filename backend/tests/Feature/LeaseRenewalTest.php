<?php

namespace Tests\Feature;

use App\Models\LeaseAgreement;
use App\Notifications\LeaseRenewalProcessedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers a gap found by this session's audit: renewal_date was set once at
 * signing and never touched again — nothing advanced it or told the
 * customer their monthly payment/EPO amount going forward.
 */
class LeaseRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_due_lease_is_renewed_and_the_customer_is_notified(): void
    {
        Notification::fake();

        $lease = LeaseAgreement::factory()->create([
            'renewal_date' => now()->subDay()->toDateString(),
            'ownership_status' => LeaseAgreement::OWNERSHIP_LEASING,
        ]);
        $originalRenewalDate = $lease->renewal_date->toDateString();

        $this->artisan('lease:process-renewals')->assertSuccessful();

        $lease->refresh();
        $this->assertNotSame($originalRenewalDate, $lease->renewal_date->toDateString());
        $this->assertTrue($lease->renewal_date->greaterThan(now()));

        Notification::assertSentTo($lease->customer, LeaseRenewalProcessedNotification::class);
    }

    public function test_a_fully_owned_lease_is_not_renewed(): void
    {
        Notification::fake();

        $lease = LeaseAgreement::factory()->create([
            'renewal_date' => now()->subDay()->toDateString(),
            'ownership_status' => LeaseAgreement::OWNERSHIP_OWNED,
        ]);
        $originalRenewalDate = $lease->renewal_date->toDateString();

        $this->artisan('lease:process-renewals')->assertSuccessful();

        $this->assertSame($originalRenewalDate, $lease->fresh()->renewal_date->toDateString());
        Notification::assertNotSentTo($lease->customer, LeaseRenewalProcessedNotification::class);
    }

    public function test_a_lease_not_yet_due_is_left_alone(): void
    {
        Notification::fake();

        $lease = LeaseAgreement::factory()->create([
            'renewal_date' => now()->addWeek()->toDateString(),
            'ownership_status' => LeaseAgreement::OWNERSHIP_LEASING,
        ]);
        $originalRenewalDate = $lease->renewal_date->toDateString();

        $this->artisan('lease:process-renewals')->assertSuccessful();

        $this->assertSame($originalRenewalDate, $lease->fresh()->renewal_date->toDateString());
        Notification::assertNotSentTo($lease->customer, LeaseRenewalProcessedNotification::class);
    }
}

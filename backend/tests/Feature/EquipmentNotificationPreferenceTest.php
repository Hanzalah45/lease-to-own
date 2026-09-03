<?php

namespace Tests\Feature;

use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use App\Models\User;
use App\Notifications\EquipmentStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers a gap found by this session's audit: EquipmentStatusChangedNotification
 * fired unconditionally on assign/release, unlike ApplicationStatusChangedNotification
 * and PaymentStatusChangedNotification which both already respect the
 * customer's status_change_emails preference.
 */
class EquipmentNotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_notification_is_skipped_when_the_customer_opted_out(): void
    {
        Notification::fake();

        $lease = LeaseAgreement::factory()->create();
        $lease->customer->customerProfile()->create(['status_change_emails' => false]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/equipment-units/{$lease->equipment_unit_id}/release", [
            'status' => EquipmentUnit::STATUS_RETURNED,
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_release_notification_still_sent_by_default(): void
    {
        Notification::fake();

        $lease = LeaseAgreement::factory()->create();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/equipment-units/{$lease->equipment_unit_id}/release", [
            'status' => EquipmentUnit::STATUS_RETURNED,
        ])->assertOk();

        Notification::assertSentTo($lease->customer, EquipmentStatusChangedNotification::class);
    }
}

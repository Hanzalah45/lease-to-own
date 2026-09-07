<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\RiskRedFlag;
use App\Models\User;
use App\Notifications\RedFlagDetectedNotification;
use App\Services\RiskRedFlagger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers a gap found by this session's audit: RiskRedFlagger::flag() wrote
 * the row but never told anyone — only the *resolved* side notified staff.
 */
class RedFlagDetectedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_flagging_notifies_risk_assessment_staff(): void
    {
        Notification::fake();

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $riskAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $riskAdmin->id, 'permission' => AdminPermission::RISK_ASSESSMENT]);
        $otherAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $otherAdmin->id, 'permission' => AdminPermission::EQUIPMENT_TRACKING]);

        RiskRedFlagger::flag($customer->id, RiskRedFlag::TYPE_UNREACHABLE_CUSTOMER, 'Customer has not answered in 2 weeks.');

        Notification::assertSentTo($riskAdmin, RedFlagDetectedNotification::class);
        Notification::assertNotSentTo($otherAdmin, RedFlagDetectedNotification::class);
    }
}

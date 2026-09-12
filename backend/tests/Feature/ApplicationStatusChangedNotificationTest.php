<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Notifications\ApplicationStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real bug found live-testing the guest flow on production (2026-09-13): the
 * "View in portal" button in this email pointed to /customer/applications
 * even for a guest-originated customer, whose account has no usable password
 * until Phase 6's account-setup step — the button was a dead end for every
 * status change before that point.
 */
class ApplicationStatusChangedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pending_guest_customer_gets_no_portal_link(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending']);
        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => Application::STATUS_WAITING_APPROVAL,
        ]);

        $data = (new ApplicationStatusChangedNotification($application))->toArray($customer);

        $this->assertNull($data['action_url']);
        $this->assertStringNotContainsString('Tap to view', $data['body']);
    }

    public function test_a_customer_with_a_real_account_still_gets_the_portal_link(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'active']);
        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => Application::STATUS_WAITING_APPROVAL,
        ]);

        $data = (new ApplicationStatusChangedNotification($application))->toArray($customer);

        $this->assertSame('/customer/applications', $data['action_url']);
    }

    public function test_mail_has_no_action_button_when_action_url_is_null(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending']);
        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => Application::STATUS_WAITING_APPROVAL,
        ]);

        $mail = (new ApplicationStatusChangedNotification($application))->toMail($customer);

        $this->assertNull($mail->actionText);
        $this->assertNull($mail->actionUrl);
    }
}

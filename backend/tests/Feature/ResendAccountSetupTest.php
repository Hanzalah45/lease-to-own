<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\ActivateAccountNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Real gap found 2026-09-21: the "Set up your account" email is sent once,
 * when a guest's first payment lands, and its link expires after 14 days.
 * "Forgot password" is no way around that (it changes the password without
 * activating the account, so login still says to verify the email), which
 * left a guest who missed the window with no way in and an admin with no
 * way to resend it.
 */
class ResendAccountSetupTest extends TestCase
{
    use RefreshDatabase;

    private function guestWithFirstPayment(bool $paid = true): User
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending']);
        $lease = LeaseAgreement::factory()->create(['customer_id' => $customer->id]);
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'status' => $paid ? Payment::STATUS_PAID : Payment::STATUS_PENDING,
        ]);

        return $customer;
    }

    public function test_admin_can_resend_the_setup_link_to_a_guest_who_has_paid(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = $this->guestWithFirstPayment();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/customers/{$customer->id}/resend-account-setup")
            ->assertOk();

        Notification::assertSentTo($customer, ActivateAccountNotification::class);
    }

    public function test_the_resent_link_actually_lets_the_customer_set_a_password_and_sign_in(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = $this->guestWithFirstPayment();
        $sentUrl = null;
        Notification::fake();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/customers/{$customer->id}/resend-account-setup")->assertOk();
        Notification::assertSentTo($customer, ActivateAccountNotification::class, function ($notification) use (&$sentUrl, $customer) {
            $sentUrl = $notification->toMail($customer)->actionUrl;

            return true;
        });

        parse_str(parse_url($sentUrl, PHP_URL_QUERY), $params);
        $this->postJson('/api/auth/account-setup', [...$params, 'password' => 'Correct-Horse-9', 'password_confirmation' => 'Correct-Horse-9'])->assertOk();

        // actingAs() above left the default guard on sanctum, which has no attempt().
        $this->app['auth']->shouldUse('web');
        $this->postJson('/api/auth/login', ['email' => $customer->email, 'password' => 'Correct-Horse-9'])->assertOk();
        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_it_is_rejected_before_the_first_payment_is_recorded(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = $this->guestWithFirstPayment(paid: false);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/customers/{$customer->id}/resend-account-setup")
            ->assertStatus(422);

        Notification::assertNothingSent();
    }

    public function test_it_is_rejected_once_the_account_is_already_set_up(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = $this->guestWithFirstPayment();
        $customer->update(['status' => 'active']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/customers/{$customer->id}/resend-account-setup")
            ->assertStatus(422);

        Notification::assertNothingSent();
    }

    public function test_it_cannot_target_an_admin_account(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $otherAdmin = User::factory()->create(['role' => User::ROLE_ADMIN, 'status' => 'pending']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/customers/{$otherAdmin->id}/resend-account-setup")
            ->assertStatus(404);
    }

    public function test_it_requires_the_application_review_permission(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $admin->id, 'permission' => AdminPermission::PAYMENT_TRACKING]);
        $customer = $this->guestWithFirstPayment();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/customers/{$customer->id}/resend-account-setup")
            ->assertStatus(403);
    }
}

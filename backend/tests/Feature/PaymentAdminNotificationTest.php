<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers a gap found by this session's audit: payment_tracking staff were
 * only notified when a payment failed, never when one was recorded paid.
 */
class PaymentAdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function paymentTrackingAdmin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $admin->id, 'permission' => AdminPermission::PAYMENT_TRACKING]);

        return $admin;
    }

    public function test_marking_a_payment_paid_notifies_payment_tracking_staff(): void
    {
        Notification::fake();

        $staff = $this->paymentTrackingAdmin();
        $lease = LeaseAgreement::factory()->create();
        $payment = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'status' => Payment::STATUS_PENDING]);

        $response = $this->actingAs($staff, 'sanctum')->putJson("/api/admin/payments/{$payment->id}", [
            'status' => Payment::STATUS_PAID,
        ]);

        $response->assertOk();
        Notification::assertSentTo($staff, PaymentStatusChangedNotification::class);
    }

    public function test_resaving_an_already_paid_payment_does_not_notify_staff_again(): void
    {
        Notification::fake();

        $staff = $this->paymentTrackingAdmin();
        $lease = LeaseAgreement::factory()->create();
        $payment = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'status' => Payment::STATUS_PAID]);

        $response = $this->actingAs($staff, 'sanctum')->putJson("/api/admin/payments/{$payment->id}", [
            'status' => Payment::STATUS_PAID,
        ]);

        $response->assertOk();
        Notification::assertNotSentTo($staff, PaymentStatusChangedNotification::class);
    }
}

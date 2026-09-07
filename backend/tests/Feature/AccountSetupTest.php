<?php

namespace Tests\Feature;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\ActivateAccountNotification;
use App\Services\AccountSetupSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers the client's 2026-09-04 requirement that a guest-originated
 * customer's real login is created by the customer themselves, at
 * first-payment/pickup time — not at application submission.
 */
class AccountSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_payment_marked_paid_sends_the_activation_link_to_a_pending_customer(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending']);
        $lease = LeaseAgreement::factory()->create(['customer_id' => $customer->id]);
        $payment = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'status' => Payment::STATUS_PENDING]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/payments/{$payment->id}", [
            'status' => Payment::STATUS_PAID,
        ]);

        $response->assertOk();
        Notification::assertSentTo($customer, ActivateAccountNotification::class);
    }

    public function test_a_second_payment_does_not_resend_the_activation_link(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending']);
        $lease = LeaseAgreement::factory()->create(['customer_id' => $customer->id]);
        Payment::factory()->create(['lease_agreement_id' => $lease->id, 'status' => Payment::STATUS_PAID]);
        $secondPayment = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'status' => Payment::STATUS_PENDING]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/payments/{$secondPayment->id}", [
            'status' => Payment::STATUS_PAID,
        ])->assertOk();

        Notification::assertNotSentTo($customer, ActivateAccountNotification::class);
    }

    public function test_an_already_active_customers_first_payment_does_not_send_an_activation_link(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'active']);
        $lease = LeaseAgreement::factory()->create(['customer_id' => $customer->id]);
        $payment = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'status' => Payment::STATUS_PENDING]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/payments/{$payment->id}", [
            'status' => Payment::STATUS_PAID,
        ])->assertOk();

        Notification::assertNotSentTo($customer, ActivateAccountNotification::class);
    }

    public function test_account_setup_sets_a_password_and_activates_the_account(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending', 'email_verified_at' => null]);
        $url = AccountSetupSigner::urlFor($customer);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $response = $this->postJson('/api/auth/account-setup', array_merge($params, [
            'password' => 'a-real-password-1',
            'password_confirmation' => 'a-real-password-1',
        ]));

        $response->assertOk();

        $customer->refresh();
        $this->assertSame('active', $customer->status);
        $this->assertNotNull($customer->email_verified_at);

        $this->postJson('/api/auth/login', [
            'email' => $customer->email,
            'password' => 'a-real-password-1',
        ])->assertOk();
    }

    public function test_account_setup_rejects_a_tampered_signature(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending']);
        $url = AccountSetupSigner::urlFor($customer);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $params['signature'] = 'tampered';

        $response = $this->postJson('/api/auth/account-setup', array_merge($params, [
            'password' => 'a-real-password-1',
            'password_confirmation' => 'a-real-password-1',
        ]));

        $response->assertStatus(422);
        $customer->refresh();
        $this->assertSame('pending', $customer->status);
    }

    public function test_account_setup_requires_password_confirmation_to_match(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending']);
        $url = AccountSetupSigner::urlFor($customer);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $response = $this->postJson('/api/auth/account-setup', array_merge($params, [
            'password' => 'a-real-password-1',
            'password_confirmation' => 'does-not-match',
        ]));

        $response->assertStatus(422);
    }
}

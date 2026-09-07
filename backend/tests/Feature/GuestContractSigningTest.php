<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\User;
use App\Notifications\RequestContractSignatureNotification;
use App\Services\ContractSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers a real gap found this session: Customer\ContractController::store()
 * sits behind auth:sanctum, but a guest-originated customer's account has no
 * usable password until their first payment (activated at pickup) — which
 * happens AFTER the contract is supposed to be signed in the confirmed flow.
 * PublicContractController + ContractSigner exist so a guest customer can
 * actually reach the signing step.
 */
class GuestContractSigningTest extends TestCase
{
    use RefreshDatabase;

    private function guestLease(string $applicationStatus = Application::STATUS_WAITING_DEPOSIT): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending']);
        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => $applicationStatus,
        ]);

        return LeaseAgreement::factory()->create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_guest_customer_can_sign_via_the_signed_link(): void
    {
        $lease = $this->guestLease();
        $url = ContractSigner::urlFor($lease->customer, $lease);
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        $response = $this->postJson('/api/contracts/verify-sign', [
            ...$params,
            'signer_name' => 'Guest Signer',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('contracts', [
            'lease_agreement_id' => $lease->id,
            'signer_name' => 'Guest Signer',
        ]);
    }

    public function test_signing_starts_the_30_day_deposit_hold(): void
    {
        $lease = $this->guestLease();
        $url = ContractSigner::urlFor($lease->customer, $lease);
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        $this->postJson('/api/contracts/verify-sign', [
            ...$params,
            'signer_name' => 'Guest Signer',
        ])->assertCreated();

        $application = $lease->application->fresh();
        $this->assertNotNull($application->deposit_hold_expires_at);
        $this->assertEqualsWithDelta(
            now()->addDays(30)->timestamp,
            $application->deposit_hold_expires_at->timestamp,
            5,
        );
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        $lease = $this->guestLease();
        $url = ContractSigner::urlFor($lease->customer, $lease);
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);
        $params['signature'] = 'not-the-real-signature';

        $response = $this->postJson('/api/contracts/verify-sign', [
            ...$params,
            'signer_name' => 'Attacker',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_a_link_cannot_be_used_to_sign_a_different_lease(): void
    {
        $lease = $this->guestLease();
        $otherLease = $this->guestLease();
        $url = ContractSigner::urlFor($lease->customer, $lease);
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);
        $params['lease'] = $otherLease->id;

        $response = $this->postJson('/api/contracts/verify-sign', [
            ...$params,
            'signer_name' => 'Wrong Lease',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_cannot_sign_before_the_application_is_eligible(): void
    {
        $lease = $this->guestLease(Application::STATUS_WAITING_REVIEW);
        $url = ContractSigner::urlFor($lease->customer, $lease);
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        $response = $this->postJson('/api/contracts/verify-sign', [
            ...$params,
            'signer_name' => 'Too Early',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_cannot_sign_a_lease_twice_via_the_link(): void
    {
        $lease = $this->guestLease();
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);
        $url = ContractSigner::urlFor($lease->customer, $lease);
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        $response = $this->postJson('/api/contracts/verify-sign', [
            ...$params,
            'signer_name' => 'Second Attempt',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('contracts', 1);
    }

    public function test_moving_to_waiting_deposit_emails_the_guest_customer_a_signing_link(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $lease = $this->guestLease(Application::STATUS_IN_VERIFICATION);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'status' => Application::STATUS_WAITING_DEPOSIT,
        ])->assertOk();

        Notification::assertSentTo($lease->customer, RequestContractSignatureNotification::class);
    }

    public function test_moving_to_waiting_deposit_does_not_email_a_customer_with_a_real_account(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'active']);
        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => Application::STATUS_IN_VERIFICATION,
        ]);
        LeaseAgreement::factory()->create(['application_id' => $application->id, 'customer_id' => $customer->id]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_WAITING_DEPOSIT,
        ])->assertOk();

        Notification::assertNotSentTo($customer, RequestContractSignatureNotification::class);
    }
}

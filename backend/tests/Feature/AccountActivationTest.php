<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\User;
use App\Services\ContractSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Consolidated guest onboarding (client, Joel, 2026-10-02): account creation
 * is now the first of two separate steps reached from the contract preview
 * link, rather than waiting for first payment/pickup. Reuses ContractSigner's
 * existing signature (the same one RequestContractSignatureNotification
 * emails) instead of a new Signer — see PublicAccountActivationController.
 */
class AccountActivationTest extends TestCase
{
    use RefreshDatabase;

    private function guestLease(): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending']);
        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => Application::STATUS_WAITING_DEPOSIT,
        ]);

        return LeaseAgreement::factory()->create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
        ]);
    }

    private function paramsFor(LeaseAgreement $lease): array
    {
        $url = ContractSigner::urlFor($lease->customer, $lease);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        return $params;
    }

    public function test_a_valid_link_activates_the_account_and_issues_a_token(): void
    {
        $lease = $this->guestLease();

        $response = $this->postJson('/api/contracts/verify-activate-account', [
            ...$this->paramsFor($lease),
            'password' => 'Correct-Horse-9',
            'password_confirmation' => 'Correct-Horse-9',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('active', $lease->customer->fresh()->status);
    }

    public function test_the_issued_token_can_immediately_sign_the_lease(): void
    {
        $lease = $this->guestLease();

        $activate = $this->postJson('/api/contracts/verify-activate-account', [
            ...$this->paramsFor($lease),
            'password' => 'Correct-Horse-9',
            'password_confirmation' => 'Correct-Horse-9',
        ])->assertOk();

        $token = $activate->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/customer/contracts', [
                'lease_agreement_id' => $lease->id,
                'signer_name' => 'Guest Signer',
            ])->assertCreated();

        $this->assertDatabaseHas('contracts', [
            'lease_agreement_id' => $lease->id,
            'signer_name' => 'Guest Signer',
        ]);
    }

    public function test_an_already_active_account_is_rejected(): void
    {
        $lease = $this->guestLease();
        $lease->customer->update(['status' => 'active']);

        $response = $this->postJson('/api/contracts/verify-activate-account', [
            ...$this->paramsFor($lease),
            'password' => 'Correct-Horse-9',
            'password_confirmation' => 'Correct-Horse-9',
        ]);

        $response->assertStatus(422);
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        $lease = $this->guestLease();
        $params = $this->paramsFor($lease);
        $params['signature'] = 'tampered';

        $response = $this->postJson('/api/contracts/verify-activate-account', [
            ...$params,
            'password' => 'Correct-Horse-9',
            'password_confirmation' => 'Correct-Horse-9',
        ]);

        $response->assertStatus(422);
        $this->assertSame('pending', $lease->customer->fresh()->status);
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $lease = $this->guestLease();
        $params = $this->paramsFor($lease);
        $params['expires'] = now()->subHour()->timestamp;

        $response = $this->postJson('/api/contracts/verify-activate-account', [
            ...$params,
            'password' => 'Correct-Horse-9',
            'password_confirmation' => 'Correct-Horse-9',
        ]);

        $response->assertStatus(422);
    }

    public function test_a_lease_belonging_to_another_customer_is_rejected(): void
    {
        $lease = $this->guestLease();
        $otherLease = $this->guestLease();
        $params = $this->paramsFor($lease);
        $params['lease'] = $otherLease->id;

        $response = $this->postJson('/api/contracts/verify-activate-account', [
            ...$params,
            'password' => 'Correct-Horse-9',
            'password_confirmation' => 'Correct-Horse-9',
        ]);

        $response->assertStatus(422);
    }

    public function test_mismatched_passwords_are_rejected(): void
    {
        $lease = $this->guestLease();

        $response = $this->postJson('/api/contracts/verify-activate-account', [
            ...$this->paramsFor($lease),
            'password' => 'Correct-Horse-9',
            'password_confirmation' => 'Different-Horse-9',
        ]);

        $response->assertStatus(422);
        $this->assertSame('pending', $lease->customer->fresh()->status);
    }
}

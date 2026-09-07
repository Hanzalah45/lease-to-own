<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\BankVerificationSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers the client's 2026-09-04 requirement that background check and bank
 * verification are two separate, admin-triggered actions — not automatic at
 * submission (see ApplicationCreationService::create()'s removed evaluate()
 * call), and each independently trackable.
 */
class RiskVerificationActionsTest extends TestCase
{
    use RefreshDatabase;

    private function applicationInVerification(): Application
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => Application::STATUS_IN_VERIFICATION,
        ]);
        LeaseAgreement::factory()->create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'monthly_rental_payment' => 200,
        ]);

        return $application;
    }

    public function test_submitting_an_application_does_not_auto_run_risk_scoring(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer, 'sanctum')->postJson('/api/customer/applications', [
            'move_notification_agreed' => true,
            'cash_price' => 3000,
            'term_months' => 24,
            'monthly_rental' => 150,
        ])->assertCreated();

        $this->assertNull(RiskProfile::where('customer_id', $customer->id)->first());
    }

    public function test_run_background_check_requires_in_verification_status(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create(['status' => Application::STATUS_WAITING_REVIEW]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/run-background-check");

        $response->assertStatus(422);
    }

    public function test_run_background_check_requires_a_lease(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create(['status' => Application::STATUS_IN_VERIFICATION]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/run-background-check");

        $response->assertStatus(422);
    }

    public function test_run_background_check_evaluates_the_risk_profile_and_notifies_staff(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = $this->applicationInVerification();

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/run-background-check");

        $response->assertOk();
        $this->assertNotNull(RiskProfile::where('customer_id', $application->customer_id)->first());
        Notification::assertSentTo($admin, \App\Notifications\BackgroundCheckRunNotification::class);
    }

    public function test_request_bank_verification_requires_in_verification_status(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create(['status' => Application::STATUS_WAITING_REVIEW]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/request-bank-verification");

        $response->assertStatus(422);
    }

    public function test_request_bank_verification_notifies_the_customer_and_marks_requested(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = $this->applicationInVerification();

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/request-bank-verification");

        $response->assertOk();
        $profile = RiskProfile::where('customer_id', $application->customer_id)->first();
        $this->assertNotNull($profile->bank_verification_requested_at);
        Notification::assertSentTo($application->customer, \App\Notifications\RequestBankVerificationNotification::class);
    }

    public function test_signed_plaid_link_works_for_a_customer_with_no_usable_password(): void
    {
        Http::fake([
            'sandbox.plaid.com/link/token/create' => Http::response(['link_token' => 'link-sandbox-fake'], 200),
        ]);

        // Mirrors a guest-originated shadow account — status pending, no login.
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending']);
        $url = BankVerificationSigner::urlFor($customer);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $response = $this->postJson('/api/plaid/verify-link-token', $params);

        $response->assertOk();
        $response->assertJson(['link_token' => 'link-sandbox-fake']);
    }

    public function test_signed_plaid_exchange_records_verification_and_recomputes_risk(): void
    {
        Http::fake([
            'sandbox.plaid.com/item/public_token/exchange' => Http::response(['access_token' => 'access-fake', 'item_id' => 'item-fake'], 200),
            'sandbox.plaid.com/accounts/get' => Http::response(['accounts' => [['name' => 'Checking', 'mask' => '1234', 'subtype' => 'checking']]], 200),
        ]);

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending']);
        $url = BankVerificationSigner::urlFor($customer);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $response = $this->postJson('/api/plaid/verify-exchange', array_merge($params, ['public_token' => 'public-sandbox-fake']));

        $response->assertOk();
        $this->assertNotNull($customer->customerProfile()->first()->bank_verified_at);
    }

    public function test_a_tampered_bank_verification_signature_is_rejected(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $url = BankVerificationSigner::urlFor($customer);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $params['signature'] = 'tampered';

        $response = $this->postJson('/api/plaid/verify-link-token', $params);

        $response->assertStatus(422);
    }
}

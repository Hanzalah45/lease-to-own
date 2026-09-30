<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\RiskRedFlag;
use App\Models\User;
use App\Services\PaymentMethodSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * AutoPay payment-method collection (client, Joel, 2026-10-01): a bank
 * account + backup card required at the waiting_deposit stage, enforced with
 * an admin override, flagged on a mismatch against the earlier Plaid
 * verification. See StripePaymentMethodService.
 */
class AutopayPaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function leaseAwaitingDeposit(): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => Application::STATUS_WAITING_DEPOSIT,
        ]);

        return LeaseAgreement::factory()->create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => null,
        ]);
    }

    private function signContract(LeaseAgreement $lease): void
    {
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);
    }

    public function test_setup_intent_creates_a_stripe_customer_and_returns_a_client_secret(): void
    {
        $lease = $this->leaseAwaitingDeposit();

        $this->stripe->queue(['id' => 'cus_test123', 'object' => 'customer']);
        $this->stripe->queue(['id' => 'seti_test123', 'object' => 'setup_intent', 'client_secret' => 'seti_test123_secret_abc']);

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/payment-methods/setup-intent", ['type' => 'card']);

        $response->assertOk();
        $response->assertJsonPath('data.client_secret', 'seti_test123_secret_abc');
        $this->assertSame('cus_test123', $lease->customer->customerProfile->fresh()->stripe_customer_id);
    }

    public function test_setup_intent_reuses_an_existing_stripe_customer(): void
    {
        $lease = $this->leaseAwaitingDeposit();
        $lease->customer->customerProfile()->create(['stripe_customer_id' => 'cus_existing']);

        $this->stripe->queue(['id' => 'seti_test456', 'object' => 'setup_intent', 'client_secret' => 'seti_test456_secret_xyz']);

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/payment-methods/setup-intent", ['type' => 'bank']);

        $response->assertOk();
        // Only one Stripe call (the SetupIntent) — no customer-creation call was needed.
        $this->assertCount(1, $this->stripe->requests);
        $this->assertSame('/v1/setup_intents', parse_url($this->stripe->requests[0]['url'], PHP_URL_PATH));
    }

    public function test_confirm_attaches_a_card_to_the_lease(): void
    {
        $lease = $this->leaseAwaitingDeposit();

        $this->stripe->queue(['id' => 'pm_card123', 'object' => 'payment_method', 'type' => 'card']);

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/payment-methods/confirm", [
                'type' => 'card',
                'payment_method_id' => 'pm_card123',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.card_added', true);
        $this->assertSame('pm_card123', $lease->fresh()->stripe_card_payment_method_id);
    }

    public function test_customer_can_choose_an_already_added_method_as_primary(): void
    {
        $lease = $this->leaseAwaitingDeposit();
        $lease->update(['stripe_card_payment_method_id' => 'pm_card123']);

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/payment-methods/primary", ['type' => 'card']);

        $response->assertOk();
        $this->assertSame('card', $lease->fresh()->autopay_primary_method);
    }

    public function test_choosing_a_method_as_primary_before_its_added_is_rejected(): void
    {
        $lease = $this->leaseAwaitingDeposit();

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/payment-methods/primary", ['type' => 'bank']);

        $response->assertStatus(422);
        $this->assertNull($lease->fresh()->autopay_primary_method);
    }

    public function test_confirming_a_bank_account_matching_the_plaid_verified_one_does_not_flag(): void
    {
        $lease = $this->leaseAwaitingDeposit();
        $lease->customer->customerProfile()->create([
            'plaid_verified_bank_name' => 'Plaid Checking',
            'plaid_verified_bank_mask' => '1234',
        ]);

        $this->stripe->queue([
            'id' => 'pm_bank123',
            'object' => 'payment_method',
            'type' => 'us_bank_account',
            'us_bank_account' => ['bank_name' => 'Stripe Test Bank', 'last4' => '1234'],
        ]);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/payment-methods/confirm", [
                'type' => 'bank',
                'payment_method_id' => 'pm_bank123',
            ])->assertOk();

        $this->assertSame(0, RiskRedFlag::where('type', RiskRedFlag::TYPE_BANK_ACCOUNT_CHANGE)->count());
    }

    public function test_confirming_a_bank_account_that_does_not_match_plaid_flags_it(): void
    {
        $lease = $this->leaseAwaitingDeposit();
        $lease->customer->customerProfile()->create([
            'plaid_verified_bank_name' => 'Plaid Checking',
            'plaid_verified_bank_mask' => '1234',
        ]);

        $this->stripe->queue([
            'id' => 'pm_bank456',
            'object' => 'payment_method',
            'type' => 'us_bank_account',
            'us_bank_account' => ['bank_name' => 'A Different Bank', 'last4' => '9999'],
        ]);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/payment-methods/confirm", [
                'type' => 'bank',
                'payment_method_id' => 'pm_bank456',
            ])->assertOk();

        $flag = RiskRedFlag::where('type', RiskRedFlag::TYPE_BANK_ACCOUNT_CHANGE)->first();
        $this->assertNotNull($flag);
        $this->assertStringContainsString('9999', $flag->description);
        $this->assertStringContainsString('1234', $flag->description);
    }

    public function test_a_customer_cannot_touch_another_customers_lease(): void
    {
        $lease = $this->leaseAwaitingDeposit();
        $otherCustomer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($otherCustomer, 'sanctum')
            ->getJson("/api/customer/lease-agreements/{$lease->id}/payment-methods");

        $response->assertNotFound();
    }

    public function test_guest_signed_link_confirm_works_without_a_login(): void
    {
        $lease = $this->leaseAwaitingDeposit();
        $lease->customer->update(['status' => 'pending']);

        $url = PaymentMethodSigner::urlFor($lease->customer, $lease);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->stripe->queue(['id' => 'pm_card789', 'object' => 'payment_method', 'type' => 'card']);

        $response = $this->postJson('/api/payment-methods/verify-confirm', [
            ...$params,
            'type' => 'card',
            'payment_method_id' => 'pm_card789',
        ]);

        $response->assertOk();
        $this->assertSame('pm_card789', $lease->fresh()->stripe_card_payment_method_id);
    }

    public function test_a_tampered_signed_link_is_rejected(): void
    {
        $lease = $this->leaseAwaitingDeposit();
        $lease->customer->update(['status' => 'pending']);

        $url = PaymentMethodSigner::urlFor($lease->customer, $lease);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $params['signature'] = 'tampered';

        $response = $this->postJson('/api/payment-methods/verify-show', $params);

        $response->assertStatus(422);
    }

    public function test_mark_deposit_received_is_blocked_without_both_payment_methods(): void
    {
        $lease = $this->leaseAwaitingDeposit();
        $application = $lease->application;
        $this->signContract($lease);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_WAITING_DELIVERY,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('AutoPay', $response->json('message'));
    }

    public function test_mark_deposit_received_succeeds_once_both_methods_are_on_file(): void
    {
        $lease = $this->leaseAwaitingDeposit();
        $lease->update(['stripe_bank_payment_method_id' => 'pm_bank1', 'stripe_card_payment_method_id' => 'pm_card1']);
        $application = $lease->application;
        $this->signContract($lease);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_WAITING_DELIVERY,
        ]);

        $response->assertOk();
    }

    public function test_admin_can_override_the_payment_methods_requirement(): void
    {
        $lease = $this->leaseAwaitingDeposit();
        $application = $lease->application;
        $this->signContract($lease);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_WAITING_DELIVERY,
            'override_payment_methods_check' => true,
        ]);

        $response->assertOk();
        $fresh = $lease->fresh();
        $this->assertSame($admin->id, $fresh->payment_methods_override_by);
        $this->assertNotNull($fresh->payment_methods_override_at);
    }

    public function test_admin_can_clear_a_payment_method_so_the_customer_can_re_add_it(): void
    {
        $lease = $this->leaseAwaitingDeposit();
        $lease->update(['stripe_card_payment_method_id' => 'pm_old_card', 'autopay_primary_method' => 'card']);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/admin/lease-agreements/{$lease->id}/payment-methods/clear",
            ['type' => 'card'],
        );

        $response->assertOk();
        $fresh = $lease->fresh();
        $this->assertNull($fresh->stripe_card_payment_method_id);
        $this->assertNull($fresh->autopay_primary_method);
    }
}

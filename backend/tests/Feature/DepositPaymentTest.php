<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\RiskRedFlag;
use App\Models\User;
use App\Notifications\PaymentStatusChangedNotification;
use App\Services\PaymentMethodSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Stripe\ApiRequestor;
use Stripe\WebhookSignature;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Real Stripe deposit charging (client, Joel, 2026-10-01) — the manual
 * "Mark Deposit Received" admin action stays a full fallback this doesn't
 * touch; see LeaseEngine::applyPaymentStatusChange()'s deposit_received sync
 * for how a successful charge and the manual flag converge.
 */
class DepositPaymentTest extends TestCase
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

    private function signedLeaseReadyForDeposit(): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => Application::STATUS_WAITING_DEPOSIT,
        ]);
        $lease = LeaseAgreement::factory()->create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => null,
            'security_deposit' => 300,
            'monthly_rental_payment' => 200,
            'sales_tax_rate' => 0,
            'ldw_selected' => false,
            'stripe_card_payment_method_id' => 'pm_card_existing',
            'autopay_primary_method' => 'card',
        ]);
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $customer->id]);

        return $lease;
    }

    public function test_charging_a_card_deposit_succeeds_synchronously(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();

        $this->stripe->queue(['id' => 'cus_test1', 'object' => 'customer']);
        $this->stripe->queue(['id' => 'pi_test1', 'object' => 'payment_intent', 'status' => 'succeeded', 'client_secret' => 'pi_test1_secret']);

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge");

        $response->assertOk();
        $response->assertJsonPath('data.requires_action', false);
        $response->assertJsonPath('data.payment.status', 'paid');

        $payment = Payment::where('lease_agreement_id', $lease->id)->where('type', Payment::TYPE_DEPOSIT)->first();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('pi_test1', $payment->stripe_payment_intent_id);
        // Deposit-only (client, Joel, 2026-10-02) — the $150 tracking fee +
        // $200 first month are now a separate chargePickupBalance(), not
        // bundled into this charge.
        $this->assertEqualsWithDelta(300.0, (float) $payment->amount, 0.01);
        $this->assertTrue($lease->application->fresh()->deposit_received);
        $this->assertSame(0, RiskRedFlag::where('type', RiskRedFlag::TYPE_FAILED_ACH)->count());
    }

    public function test_charging_an_ach_deposit_leaves_it_processing(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        $lease->update(['stripe_bank_payment_method_id' => 'pm_bank_existing', 'autopay_primary_method' => 'ach']);

        $this->stripe->queue(['id' => 'cus_test2', 'object' => 'customer']);
        $this->stripe->queue(['id' => 'pi_test2', 'object' => 'payment_intent', 'status' => 'processing', 'client_secret' => 'pi_test2_secret']);

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge");

        $response->assertOk();
        $response->assertJsonPath('data.requires_action', false);
        $response->assertJsonPath('data.payment.status', 'pending');

        $this->assertFalse($lease->application->fresh()->deposit_received);
    }

    public function test_a_declined_card_marks_the_deposit_failed_and_flags_it(): void
    {
        Notification::fake();
        $lease = $this->signedLeaseReadyForDeposit();

        $this->stripe->queue(['id' => 'cus_test3', 'object' => 'customer']);
        $this->stripe->queue([
            'error' => [
                'message' => 'Your card was declined.',
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'generic_decline',
                'payment_intent' => ['id' => 'pi_test3', 'object' => 'payment_intent', 'status' => 'requires_payment_method'],
            ],
        ], 402);

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge");

        $response->assertStatus(422);

        $payment = Payment::where('lease_agreement_id', $lease->id)->where('type', Payment::TYPE_DEPOSIT)->first();
        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertSame('pi_test3', $payment->stripe_payment_intent_id);
        $this->assertSame(1, RiskRedFlag::where('type', RiskRedFlag::TYPE_FAILED_ACH)->count());
        $this->assertFalse($lease->application->fresh()->deposit_received);
        Notification::assertSentTo($lease->customer, PaymentStatusChangedNotification::class);
    }

    public function test_cannot_charge_the_same_deposit_twice(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        $this->stripe->queue(['id' => 'cus_test4', 'object' => 'customer']);
        $this->stripe->queue(['id' => 'pi_test4', 'object' => 'payment_intent', 'status' => 'succeeded']);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge")->assertOk();

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge");

        $response->assertStatus(422);
        $this->assertSame(1, Payment::where('lease_agreement_id', $lease->id)->where('type', Payment::TYPE_DEPOSIT)->count());
    }

    public function test_cannot_charge_while_a_previous_attempt_is_still_processing(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_DEPOSIT,
            'status' => Payment::STATUS_PENDING,
            'stripe_payment_intent_id' => 'pi_already_processing',
        ]);

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge");

        $response->assertStatus(422);
        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_cannot_charge_with_no_payment_method_on_file(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        $lease->update(['stripe_card_payment_method_id' => null, 'autopay_primary_method' => null]);

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge");

        $response->assertStatus(422);
        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_cannot_charge_before_the_contract_is_signed(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => Application::STATUS_WAITING_DEPOSIT]);
        $lease = LeaseAgreement::factory()->create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => null,
            'stripe_card_payment_method_id' => 'pm_card_existing',
            'autopay_primary_method' => 'card',
        ]);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge");

        $response->assertStatus(422);
        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_cannot_charge_once_deposit_is_already_marked_received_manually(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        $lease->application->update(['deposit_received' => true]);

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge");

        $response->assertStatus(422);
        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_prefers_autopay_primary_method_over_the_other_one_on_file(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        $lease->update(['stripe_bank_payment_method_id' => 'pm_bank_existing', 'autopay_primary_method' => 'ach']);

        $this->stripe->queue(['id' => 'cus_test5', 'object' => 'customer']);
        $this->stripe->queue(['id' => 'pi_test5', 'object' => 'payment_intent', 'status' => 'processing']);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge")->assertOk();

        $intentRequest = collect($this->stripe->requests)->first(fn ($r) => str_contains($r['url'], '/payment_intents'));
        $this->assertSame('pm_bank_existing', $intentRequest['params']['payment_method']);
        $this->assertSame(['us_bank_account'], $intentRequest['params']['payment_method_types']);
    }

    public function test_falls_back_to_whichever_method_is_on_file_when_no_primary_is_chosen(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        $lease->update(['autopay_primary_method' => null]); // only the card (from the base fixture) is on file

        $this->stripe->queue(['id' => 'cus_test6', 'object' => 'customer']);
        $this->stripe->queue(['id' => 'pi_test6', 'object' => 'payment_intent', 'status' => 'succeeded']);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge")->assertOk();

        $intentRequest = collect($this->stripe->requests)->first(fn ($r) => str_contains($r['url'], '/payment_intents'));
        $this->assertSame('pm_card_existing', $intentRequest['params']['payment_method']);
    }

    public function test_a_customer_cannot_charge_another_customers_lease(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        $otherCustomer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($otherCustomer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge");

        $response->assertStatus(404);
    }

    public function test_guest_signed_link_charge_works_without_a_login(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        $lease->customer->update(['status' => 'pending']);

        $url = PaymentMethodSigner::urlFor($lease->customer, $lease);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->stripe->queue(['id' => 'cus_test7', 'object' => 'customer']);
        $this->stripe->queue(['id' => 'pi_test7', 'object' => 'payment_intent', 'status' => 'succeeded']);

        $response = $this->postJson('/api/deposit-payments/verify-charge', $params);

        $response->assertOk();
        $response->assertJsonPath('data.payment.status', 'paid');
    }

    public function test_a_tampered_signed_link_is_rejected_for_the_deposit_endpoint(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        $lease->customer->update(['status' => 'pending']);

        $url = PaymentMethodSigner::urlFor($lease->customer, $lease);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $params['signature'] = 'tampered';

        $response = $this->postJson('/api/deposit-payments/verify-show', $params);

        $response->assertStatus(422);
    }

    public function test_charging_the_pickup_balance_succeeds_independently_of_the_deposit(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();

        $this->stripe->queue(['id' => 'cus_test8', 'object' => 'customer']);
        $this->stripe->queue(['id' => 'pi_test8', 'object' => 'payment_intent', 'status' => 'succeeded']);

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge-balance");

        $response->assertOk();
        $response->assertJsonPath('data.payment.status', 'paid');

        $payment = Payment::where('lease_agreement_id', $lease->id)->where('type', Payment::TYPE_PICKUP_BALANCE)->first();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertEqualsWithDelta(350.0, (float) $payment->amount, 0.01); // 150 tracking fee + 200 first month
        $this->assertTrue($lease->application->fresh()->pickup_balance_received);
        // Charging the balance never touches the deposit row.
        $this->assertSame(0, Payment::where('lease_agreement_id', $lease->id)->where('type', Payment::TYPE_DEPOSIT)->count());
    }

    public function test_pay_in_full_is_two_independent_charges_that_never_share_an_intent(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();

        // ensureStripeCustomer() only calls Stripe once and caches
        // stripe_customer_id on the profile — only the first charge's
        // "create customer" response is ever consumed.
        $this->stripe->queue(['id' => 'cus_test9', 'object' => 'customer']);
        $this->stripe->queue(['id' => 'pi_test9_deposit', 'object' => 'payment_intent', 'status' => 'succeeded']);
        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge")->assertOk();

        $this->stripe->queue(['id' => 'pi_test9_balance', 'object' => 'payment_intent', 'status' => 'succeeded']);
        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge-balance")->assertOk();

        $deposit = Payment::where('lease_agreement_id', $lease->id)->where('type', Payment::TYPE_DEPOSIT)->first();
        $balance = Payment::where('lease_agreement_id', $lease->id)->where('type', Payment::TYPE_PICKUP_BALANCE)->first();
        $this->assertSame(Payment::STATUS_PAID, $deposit->status);
        $this->assertSame(Payment::STATUS_PAID, $balance->status);
        $this->assertNotSame($deposit->stripe_payment_intent_id, $balance->stripe_payment_intent_id);
        $this->assertTrue($lease->application->fresh()->deposit_received);
        $this->assertTrue($lease->application->fresh()->pickup_balance_received);
    }

    public function test_cannot_charge_the_pickup_balance_twice(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        $this->stripe->queue(['id' => 'cus_test10', 'object' => 'customer']);
        $this->stripe->queue(['id' => 'pi_test10', 'object' => 'payment_intent', 'status' => 'succeeded']);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge-balance")->assertOk();

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge-balance");

        $response->assertStatus(422);
        $this->assertSame(1, Payment::where('lease_agreement_id', $lease->id)->where('type', Payment::TYPE_PICKUP_BALANCE)->count());
    }

    public function test_pickup_balance_is_still_chargeable_once_waiting_on_delivery(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        $lease->application->update(['status' => Application::STATUS_WAITING_DELIVERY, 'deposit_received' => true]);

        $this->stripe->queue(['id' => 'cus_test11', 'object' => 'customer']);
        $this->stripe->queue(['id' => 'pi_test11', 'object' => 'payment_intent', 'status' => 'succeeded']);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge-balance")
            ->assertOk();
    }

    public function test_cannot_charge_the_pickup_balance_before_the_contract_is_signed(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => Application::STATUS_WAITING_DEPOSIT]);
        $lease = LeaseAgreement::factory()->create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => null,
            'stripe_card_payment_method_id' => 'pm_card_existing',
            'autopay_primary_method' => 'card',
        ]);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge-balance");

        $response->assertStatus(422);
        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_guest_signed_link_can_charge_the_pickup_balance(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        $lease->customer->update(['status' => 'pending']);

        $url = PaymentMethodSigner::urlFor($lease->customer, $lease);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $this->stripe->queue(['id' => 'cus_test12', 'object' => 'customer']);
        $this->stripe->queue(['id' => 'pi_test12', 'object' => 'payment_intent', 'status' => 'succeeded']);

        $response = $this->postJson('/api/deposit-payments/verify-charge-balance', $params);

        $response->assertOk();
        $response->assertJsonPath('data.payment.status', 'paid');
    }

    public function test_webhook_payment_intent_succeeded_also_sets_deposit_received(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        $payment = Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_DEPOSIT,
            'status' => Payment::STATUS_PENDING,
            'stripe_payment_intent_id' => 'pi_webhook_test',
        ]);

        config(['services.stripe.webhook_secret' => 'whsec_test_secret_for_specs_only']);
        $event = [
            'id' => 'evt_'.Str::random(16),
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_webhook_test', 'object' => 'payment_intent', 'status' => 'succeeded']],
        ];
        $payload = json_encode($event);
        $signature = WebhookSignature::generateSignatureHeader($payload, 'whsec_test_secret_for_specs_only');

        $response = $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => $signature,
        ], $payload);

        $response->assertOk();
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertTrue($lease->application->fresh()->deposit_received);
    }

    public function test_webhook_payment_intent_succeeded_also_sets_pickup_balance_received(): void
    {
        $lease = $this->signedLeaseReadyForDeposit();
        $payment = Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_PICKUP_BALANCE,
            'status' => Payment::STATUS_PENDING,
            'stripe_payment_intent_id' => 'pi_webhook_test_balance',
        ]);

        config(['services.stripe.webhook_secret' => 'whsec_test_secret_for_specs_only']);
        $event = [
            'id' => 'evt_'.Str::random(16),
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_webhook_test_balance', 'object' => 'payment_intent', 'status' => 'succeeded']],
        ];
        $payload = json_encode($event);
        $signature = WebhookSignature::generateSignatureHeader($payload, 'whsec_test_secret_for_specs_only');

        $response = $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => $signature,
        ], $payload);

        $response->assertOk();
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertTrue($lease->application->fresh()->pickup_balance_received);
        $this->assertFalse($lease->application->fresh()->deposit_received);
    }
}

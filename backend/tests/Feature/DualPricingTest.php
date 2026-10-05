<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AutopayPaymentReminderNotification;
use App\Notifications\PaymentStatusChangedNotification;
use App\Services\LeaseEngine;
use App\Services\PaymentMethodSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Dual pricing (client, Joel, 2026-10-05): every card payment costs 3% more
 * than the bank price. Payment.amount stays the BANK price (so ownership,
 * EPO and late fees are untouched); card_fee_amount holds the extra.
 */
class DualPricingTest extends TestCase
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

    /** $300 deposit, $200 rent with 7% tax ($214 monthly), so the pickup balance is $150 + $214 = $364. */
    private function lease(array $overrides = []): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => Application::STATUS_WAITING_DEPOSIT]);
        $lease = LeaseAgreement::factory()->create(array_merge([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => null,
            'security_deposit' => 300,
            'monthly_rental_payment' => 200,
            'sales_tax_rate' => 0.07,
            'ldw_selected' => false,
            'stripe_card_payment_method_id' => 'pm_card_existing',
            'stripe_bank_payment_method_id' => 'pm_bank_existing',
            'autopay_primary_method' => 'card',
        ], $overrides));
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $customer->id]);

        return $lease;
    }

    private function intentRequest(): ?array
    {
        return collect($this->stripe->requests)->first(fn ($r) => str_contains($r['url'], '/payment_intents'));
    }

    private function queueSuccessfulCharge(string $id = 'pi_ok'): void
    {
        $this->stripe->queue(['id' => 'cus_dual', 'object' => 'customer']);
        $this->stripe->queue(['id' => $id, 'object' => 'payment_intent', 'status' => 'succeeded']);
    }

    public function test_a_card_deposit_charges_three_percent_more_and_stores_the_fee_separately(): void
    {
        $lease = $this->lease();
        $this->queueSuccessfulCharge();

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge", ['method' => 'card'])
            ->assertOk();

        $this->assertSame(30900, $this->intentRequest()['params']['amount']);
        $this->assertSame(['card'], $this->intentRequest()['params']['payment_method_types']);

        $payment = Payment::where('type', Payment::TYPE_DEPOSIT)->firstOrFail();
        $this->assertSame('300.00', $payment->amount);          // the BANK price
        $this->assertSame('9.00', $payment->card_fee_amount);   // the card premium
        $this->assertSame(309.0, $payment->totalCharged());
        $this->assertSame('card', $payment->method);
    }

    public function test_a_bank_deposit_charges_exactly_the_bank_price_with_no_fee(): void
    {
        $lease = $this->lease();
        $this->queueSuccessfulCharge();

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge", ['method' => 'bank'])
            ->assertOk();

        $this->assertSame(30000, $this->intentRequest()['params']['amount']);
        $this->assertSame(['us_bank_account'], $this->intentRequest()['params']['payment_method_types']);
        $this->assertSame('pm_bank_existing', $this->intentRequest()['params']['payment_method']);

        $payment = Payment::where('type', Payment::TYPE_DEPOSIT)->firstOrFail();
        $this->assertSame('0.00', $payment->card_fee_amount);
        $this->assertSame('ach', $payment->method);
    }

    public function test_the_explicit_choice_overrides_the_autopay_primary(): void
    {
        $lease = $this->lease(['autopay_primary_method' => 'card']);
        $this->queueSuccessfulCharge();

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge", ['method' => 'bank'])
            ->assertOk();

        $this->assertSame('pm_bank_existing', $this->intentRequest()['params']['payment_method']);
    }

    public function test_with_no_choice_the_autopay_primary_is_used_and_priced_accordingly(): void
    {
        $lease = $this->lease(['autopay_primary_method' => 'card']);
        $this->queueSuccessfulCharge();

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge")
            ->assertOk();

        $this->assertSame(30900, $this->intentRequest()['params']['amount']);
    }

    public function test_choosing_a_method_that_is_not_on_file_is_rejected(): void
    {
        $lease = $this->lease(['stripe_bank_payment_method_id' => null]);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge", ['method' => 'bank'])
            ->assertStatus(422);

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_the_pickup_balance_is_priced_as_one_charge(): void
    {
        $lease = $this->lease();
        $this->queueSuccessfulCharge();

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge-balance", ['method' => 'card'])
            ->assertOk();

        // $150 tracking fee + $214 first month = $364 -> +3% = $374.92
        $this->assertSame(37492, $this->intentRequest()['params']['amount']);
        $payment = Payment::where('type', Payment::TYPE_PICKUP_BALANCE)->firstOrFail();
        $this->assertSame('364.00', $payment->amount);
        $this->assertSame('10.92', $payment->card_fee_amount);
    }

    public function test_a_stale_expected_total_is_refused_before_anything_is_charged(): void
    {
        $lease = $this->lease();

        // The screen showed the bank price ($300.00) but the method resolved to card ($309.00).
        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge", ['method' => 'card', 'expected_total_cents' => 30000])
            ->assertStatus(409);

        $this->assertCount(0, $this->stripe->requests);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_matching_expected_total_is_charged(): void
    {
        $lease = $this->lease();
        $this->queueSuccessfulCharge();

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge", ['method' => 'card', 'expected_total_cents' => 30900])
            ->assertOk();

        $this->assertSame(30900, $this->intentRequest()['params']['amount']);
    }

    public function test_the_guest_signed_link_supports_the_same_choice(): void
    {
        $lease = $this->lease();
        $lease->customer->update(['status' => 'pending']);
        parse_str(parse_url(PaymentMethodSigner::urlFor($lease->customer, $lease), PHP_URL_QUERY), $params);
        $this->queueSuccessfulCharge();

        $this->postJson('/api/deposit-payments/verify-charge', [...$params, 'method' => 'card', 'expected_total_cents' => 30900])->assertOk();

        $this->assertSame(30900, $this->intentRequest()['params']['amount']);
    }

    public function test_the_status_response_shows_both_prices_and_the_methods_on_file(): void
    {
        $lease = $this->lease(['stripe_bank_payment_method_id' => null]);

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->getJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment");

        $response->assertOk();
        $response->assertJsonPath('data.prices.card_fee_percent', 3);
        $response->assertJsonPath('data.prices.deposit.bank', 300);
        $response->assertJsonPath('data.prices.deposit.card', 309);
        $response->assertJsonPath('data.prices.pickup_balance.bank', 364);
        $response->assertJsonPath('data.prices.pickup_balance.card', 374.92);
        $response->assertJsonPath('data.prices.full.bank', 664);
        $response->assertJsonPath('data.prices.full.card', 683.92);
        $response->assertJsonPath('data.prices.monthly.card', 220.42);
        $response->assertJsonPath('data.available_methods', ['card']);
    }

    // ---- notifications show what was / will be charged ----

    public function test_the_reminder_for_a_card_primary_shows_the_card_total_and_the_bank_alternative(): void
    {
        $lease = $this->lease(['autopay_primary_method' => 'card']);
        $payment = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'amount' => 214, 'due_date' => '2026-10-15']);

        $body = (new AutopayPaymentReminderNotification($payment))->toArray($lease->customer)['body'];

        $this->assertStringContainsString('$220.42 autopay payment', $body);
        $this->assertStringContainsString('$6.42 card fee', $body);
        $this->assertStringContainsString('bank account instead would be $214.00', $body);
    }

    public function test_the_reminder_for_a_bank_primary_shows_the_plain_price(): void
    {
        $lease = $this->lease(['autopay_primary_method' => 'ach']);
        $payment = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'amount' => 214, 'due_date' => '2026-10-15']);

        $body = (new AutopayPaymentReminderNotification($payment))->toArray($lease->customer)['body'];

        $this->assertStringContainsString('$214.00 autopay payment', $body);
        $this->assertStringNotContainsString('card fee', $body);
    }

    public function test_a_paid_card_payment_notification_states_the_total_and_the_fee(): void
    {
        $lease = $this->lease();
        $payment = Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'amount' => 214,
            'card_fee_amount' => 6.42,
            'status' => Payment::STATUS_PAID,
            'due_date' => '2026-10-15',
        ]);

        $body = (new PaymentStatusChangedNotification($payment))->toArray($lease->customer)['body'];

        $this->assertStringContainsString('$220.42 payment (includes a $6.42 card fee)', $body);
    }

    // ---- the card premium must never leak into ownership math ----

    public function test_the_card_fee_never_counts_toward_ownership_epo_or_late_fees(): void
    {
        $lease = $this->lease(['term_months' => 36, 'monthly_rental_payment' => 150, 'sales_tax_rate' => 0]);
        $lease->application->update(['status' => Application::STATUS_FINISHED]);
        foreach (['2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01'] as $due) {
            Payment::factory()->create([
                'lease_agreement_id' => $lease->id,
                'due_date' => $due,
                'status' => Payment::STATUS_PAID,
                'amount' => 150,
                'card_fee_amount' => 4.50,
                'method' => 'card',
            ]);
        }
        $lease = $lease->fresh();
        LeaseEngine::syncPaymentsPaidToDate($lease);

        $this->assertSame(4, $lease->paymentsMadeCount());
        $this->assertSame('600.00', $lease->fresh()->rental_payments_paid_to_date); // 4 x $150, not $618
        $epoWithFees = LeaseEngine::epoToday($lease->fresh());

        Payment::query()->update(['card_fee_amount' => 0]);
        $this->assertSame($epoWithFees, LeaseEngine::epoToday($lease->fresh()));

        $overdue = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'amount' => 200, 'card_fee_amount' => 6, 'due_date' => '2026-01-01']);
        $this->assertSame(20.0, LeaseEngine::lateFeeFor($overdue)); // 10% of the $200 bank price, not $206
    }
}

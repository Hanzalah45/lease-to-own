<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\User;
use App\Services\ApplicationCreationService;
use App\Services\LeasePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The price calculator (client, Joel, 2026-10-06): the same pricing as the
 * New Application wizard, with no customer attached. It must agree to the
 * cent with the official pricing blueprint and with the lease a real
 * application stores.
 */
class PriceQuoteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    private function quote(array $overrides = [])
    {
        return $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/pricing/quote', array_merge([
            'cash_price' => 4899,
            'tax_rate' => 0,
            'term_months' => 36,
            'ldw' => 'yes',
        ], $overrides));
    }

    public function test_it_matches_the_blueprints_worked_example_with_ldw(): void
    {
        // Official blueprint: $4,899 / 36 months with LDW -> $284.16 a month,
        // $342.93 deposit, $777.09 due (deposit + first month + $150 fee).
        $this->quote()
            ->assertOk()
            ->assertJsonPath('data.monthly_rental', 247.42)
            ->assertJsonPath('data.ldw_amount', 36.74)
            ->assertJsonPath('data.total_monthly_payment', 284.16)
            ->assertJsonPath('data.security_deposit', 342.93)
            ->assertJsonPath('data.tracking_device_fee', 150)
            ->assertJsonPath('data.total_due_today', 777.09);
    }

    public function test_declining_ldw_adds_no_surcharge_and_a_three_month_deposit(): void
    {
        $this->quote(['ldw' => 'no'])
            ->assertOk()
            ->assertJsonPath('data.ldw_amount', 0)
            ->assertJsonPath('data.total_monthly_payment', 247.42)
            ->assertJsonPath('data.security_deposit', 742.26);
    }

    public function test_sales_tax_applies_to_rental_plus_ldw(): void
    {
        $this->quote(['tax_rate' => 8.25])
            ->assertOk()
            ->assertJsonPath('data.sales_tax', 23.44)
            ->assertJsonPath('data.total_monthly_payment', 307.6);
    }

    public function test_it_shows_both_the_bank_and_the_card_price(): void
    {
        $response = $this->quote(['cash_price' => 4200, 'term_months' => 24, 'ldw' => 'no', 'tax_rate' => 0]);

        // 4200 / 16 = 262.50 a month; the card price adds 3%, rounded half up.
        $response->assertOk()
            ->assertJsonPath('data.pricing.card_fee_percent', 3)
            ->assertJsonPath('data.pricing.monthly.bank', 262.5)
            ->assertJsonPath('data.pricing.monthly.card', 270.38);
    }

    public function test_it_returns_the_payoff_schedule_the_wizard_chart_plots(): void
    {
        $schedule = $this->quote(['term_months' => 12, 'cash_price' => 1200, 'ldw' => 'no'])
            ->assertOk()
            ->json('data.epo_schedule');

        $this->assertSame([1, 3, 6, 9, 12], array_column($schedule, 'month'));
        // First 90 days: cash price minus payments scheduled to date (1200 / 10 = 120).
        $this->assertSame(1080.0, (float) $schedule[0]['value']);
        $this->assertSame(0.0, (float) end($schedule)['value']);
    }

    public function test_a_term_without_a_defined_price_is_rejected(): void
    {
        $this->quote(['term_months' => 18])->assertStatus(422)->assertJsonValidationErrors('term_months');
    }

    public function test_nonsense_amounts_are_rejected(): void
    {
        $this->quote(['cash_price' => 0])->assertStatus(422)->assertJsonValidationErrors('cash_price');
        $this->quote(['cash_price' => -5])->assertStatus(422)->assertJsonValidationErrors('cash_price');
        $this->quote(['cash_price' => LeasePricing::CASH_PRICE_MAX + 1])->assertStatus(422)->assertJsonValidationErrors('cash_price');
        $this->quote(['tax_rate' => 101])->assertStatus(422)->assertJsonValidationErrors('tax_rate');
    }

    public function test_it_needs_no_customer_and_creates_nothing(): void
    {
        $before = Application::count();

        $this->quote()->assertOk();

        $this->assertSame($before, Application::count());
    }

    public function test_only_admins_with_application_access_can_use_it(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->actingAs($customer, 'sanctum')->postJson('/api/admin/pricing/quote', ['cash_price' => 1000, 'term_months' => 12])->assertForbidden();

        $payments = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $payments->id, 'permission' => AdminPermission::PAYMENT_TRACKING]);
        $this->actingAs($payments, 'sanctum')->postJson('/api/admin/pricing/quote', ['cash_price' => 1000, 'term_months' => 12])->assertForbidden();

        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);
        $this->actingAs($reviewer, 'sanctum')->postJson('/api/admin/pricing/quote', ['cash_price' => 1000, 'term_months' => 12])->assertOk();
    }

    public function test_a_quote_equals_what_a_real_application_stores(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create(['customer_id' => $customer->id]);

        ApplicationCreationService::attachLease($application, [
            'make' => 'Toro', 'model' => 'Z Master', 'serial' => 'QUOTE-1', 'condition' => 'new',
            'cash_price' => 6149, 'term_months' => 24, 'tax_rate' => 8.25, 'ldw' => 'yes',
            'monthly_rental' => 0,
        ]);
        $lease = $application->fresh()->leaseAgreement;
        $quote = LeasePricing::quote(6149, 8.25, 24, true);

        $this->assertEqualsWithDelta($lease->totalMonthlyPayment(), $quote['total_monthly_payment'], 0.001);
        $this->assertEqualsWithDelta((float) $lease->security_deposit, $quote['security_deposit'], 0.001);
        $this->assertEqualsWithDelta($lease->totalDueAtSigning(), $quote['total_due_today'], 0.001);
        $this->assertEqualsWithDelta((float) $lease->total_rental_purchase_price, $quote['total_rental_purchase_price'], 0.001);
        $this->assertSame($lease->pricingSummary(), $quote['pricing']);
    }
}

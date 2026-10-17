<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sales tax and payment reports (client, Joel, 2026-10-16: "as we collect sales tax, we will need the correct reports for
 * sales tax"): what was collected by month with the tax inside it, and a download for the books.
 */
class PaymentReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20 15:00:00', 'UTC'));
    }

    /** Rent $200 at 8.25% tax: a monthly payment is $216.50, of which $16.50 is sales tax. */
    private function lease(string $name = 'Report Customer'): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => $name]);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => Application::STATUS_FINISHED]);

        return LeaseAgreement::factory()->create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => null,
            'monthly_rental_payment' => 200,
            'sales_tax_rate' => 0.0825,
            'ldw_selected' => false,
            'ldw_amount' => 0,
        ]);
    }

    private function paid(LeaseAgreement $lease, string $type, float $amount, string $date, array $extra = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'lease_agreement_id' => $lease->id,
            'type' => $type,
            'amount' => $amount,
            'due_date' => $date,
            'paid_date' => $date,
            'status' => Payment::STATUS_PAID,
            'method' => 'ach',
        ], $extra));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    private function collectedPayments(): LeaseAgreement
    {
        $lease = $this->lease();
        // The first month was collected inside the tracking fee and first month charge (card, with its fee), and the first
        // monthly row that delivery records next to it is only its mirror.
        $this->paid($lease, Payment::TYPE_PICKUP_BALANCE, 366.50, '2026-09-28', ['method' => 'card', 'card_fee_amount' => 11.00, 'stripe_payment_intent_id' => 'pi_pickup']);
        $this->paid($lease, Payment::TYPE_RENTAL, 216.50, '2026-09-28', ['method' => null]);
        $this->paid($lease, Payment::TYPE_RENTAL, 30.00, '2026-10-01');
        $this->paid($lease, Payment::TYPE_RENTAL, 216.50, '2026-10-15');
        $this->paid($lease, Payment::TYPE_DEPOSIT, 300, '2026-09-27');
        $this->paid($lease, Payment::TYPE_LATE_FEE, 20, '2026-10-16');
        $this->paid($lease, Payment::TYPE_EXTRA_PAYMENT, 100, '2026-10-17', ['method' => 'check']);
        // Not collected: still pending, failed, or paid before the range.
        Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 216.50, 'due_date' => '2026-11-15', 'status' => Payment::STATUS_PENDING]);
        Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 216.50, 'due_date' => '2026-10-10', 'status' => Payment::STATUS_FAILED]);
        // (a payment of another lease, paid before the range)
        $this->paid($this->lease('Earlier Customer'), Payment::TYPE_RENTAL, 216.50, '2025-12-15');

        return $lease;
    }

    public function test_the_summary_counts_what_was_collected_with_the_tax_inside_it_by_month(): void
    {
        $this->collectedPayments();

        $response = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/reports/sales-tax?from=2026-01-01&to=2026-10-31')->assertOk();
        $months = collect($response->json('data.months'))->keyBy('month');

        // September: the tracking fee and first month ($150 + $216.50, no double count of the mirror) and the deposit.
        $this->assertEqualsWithDelta(16.50, $months['2026-09']['sales_tax'], 0.001);
        $this->assertEqualsWithDelta(200.00, $months['2026-09']['taxable_sales'], 0.001);
        $this->assertEqualsWithDelta(150.00, $months['2026-09']['tracking_fees'], 0.001);
        $this->assertEqualsWithDelta(300.00, $months['2026-09']['deposits'], 0.001);
        $this->assertEqualsWithDelta(11.00, $months['2026-09']['card_fees'], 0.001);
        $this->assertEqualsWithDelta(366.50 + 11.00 + 300.00, $months['2026-09']['total_collected'], 0.001);
        $this->assertSame(2, $months['2026-09']['payments']);

        // October: a prorated payment carries a proportional share of the tax, a full one all of it, and a late fee and
        // an extra payment carry none.
        $prorated = round(30 * (16.50 / 216.50), 2);
        $this->assertEqualsWithDelta(16.50 + $prorated, $months['2026-10']['sales_tax'], 0.001);
        $this->assertEqualsWithDelta(216.50 - 16.50 + 30 - $prorated, $months['2026-10']['taxable_sales'], 0.001);
        $this->assertEqualsWithDelta(20.00, $months['2026-10']['late_fees'], 0.001);
        $this->assertEqualsWithDelta(100.00, $months['2026-10']['extra_payments'], 0.001);

        // The payment before the range, the pending one and the failed one are not counted.
        $this->assertNull($months['2025-12'] ?? null);
        $this->assertSame(6, (int) $response->json('data.totals.payments'));
        $this->assertEqualsWithDelta(366.50 + 11.00 + 300.00 + 30 + 216.50 + 20 + 100, $response->json('data.totals.total_collected'), 0.001);
        $this->assertEqualsWithDelta(100.0, $response->json('data.by_method.check'), 0.001);
        $this->assertEqualsWithDelta(377.50, $response->json('data.by_method.card'), 0.001);
    }

    public function test_the_download_lists_every_collected_payment_and_keeps_a_name_from_being_run_as_a_formula(): void
    {
        $lease = $this->lease('=HYPERLINK("http://example.test")');
        $this->paid($lease, Payment::TYPE_RENTAL, 216.50, '2026-10-15');
        $this->paid($lease, Payment::TYPE_DEPOSIT, 300, '2026-10-01');

        $response = $this->actingAs($this->admin(), 'sanctum')->get('/api/admin/reports/payments.csv?from=2026-10-01&to=2026-10-31');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('payments-2026-10-01-to-2026-10-31.csv', $response->headers->get('Content-Disposition'));
        $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
        $this->assertSame('"Paid on",Customer,Email,Lease,Type,Method,"Amount (bank price)","Taxable sales","Sales tax","Tracking fee","Card fee","Total collected"', $lines[0]);
        $this->assertCount(3, $lines);
        $this->assertStringContainsString("2026-10-15,\"'=HYPERLINK", $lines[2]);
        $this->assertStringContainsString(',16.5,', $lines[2]);
    }

    public function test_only_staff_with_payment_tracking_can_see_the_reports(): void
    {
        $lease = $this->lease();

        $this->actingAs($lease->customer, 'sanctum')->getJson('/api/admin/reports/sales-tax')->assertForbidden();

        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);
        $this->actingAs($reviewer, 'sanctum')->getJson('/api/admin/reports/sales-tax')->assertForbidden();
        $this->actingAs($reviewer, 'sanctum')->get('/api/admin/reports/payments.csv')->assertForbidden();
    }

    public function test_a_range_that_ends_before_it_starts_is_refused(): void
    {
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/reports/sales-tax?from=2026-10-10&to=2026-10-01')->assertStatus(422);
    }
}

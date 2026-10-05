<?php

namespace Tests\Unit;

use App\Services\BillingSchedule;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The client's (Joel, 2026-10-05) fixed two-cycle billing rules. All examples
 * use a $300 monthly payment so the arithmetic is checkable by eye.
 */
class BillingScheduleTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: float, 5: bool}> */
    public static function secondPaymentCases(): array
    {
        return [
            'start Oct 7, pick the 15th: 8 days' => ['2026-10-07', '15th', '2026-10-15', 8, 80.0, true],
            'start Oct 19, pick the 1st: 13 days' => ['2026-10-19', '1st', '2026-11-01', 13, 130.0, true],
            'start on the cycle day (15th) points to next month, 31 days: full' => ['2026-10-15', '15th', '2026-11-15', 31, 300.0, false],
            'start on the 1st, pick the 1st: 31 days, full' => ['2026-10-01', '1st', '2026-11-01', 31, 300.0, false],
            'exactly 30 days is NOT prorated' => ['2026-09-01', '1st', '2026-10-01', 30, 300.0, false],
            '29 days is prorated' => ['2026-09-02', '1st', '2026-10-01', 29, 290.0, true],
            'February 1 (28 days) prorates literally' => ['2027-02-01', '1st', '2027-03-01', 28, 280.0, true],
            'leap-year February 1 is 29 days' => ['2028-02-01', '1st', '2028-03-01', 29, 290.0, true],
            'start on the 31st, pick the 1st: one day' => ['2026-10-31', '1st', '2026-11-01', 1, 10.0, true],
            'start on the 14th, pick the 15th: one day' => ['2026-10-14', '15th', '2026-10-15', 1, 10.0, true],
            'year rollover' => ['2026-12-31', '1st', '2027-01-01', 1, 10.0, true],
        ];
    }

    #[DataProvider('secondPaymentCases')]
    public function test_second_payment_date_and_amount(string $start, string $cycle, string $secondDate, int $days, float $secondAmount, bool $prorated): void
    {
        $rows = BillingSchedule::build($start, $cycle, 300.0, 36);

        $this->assertSame($start, $rows[0]['due_date']);
        $this->assertSame(300.0, $rows[0]['amount']);
        $this->assertFalse($rows[0]['prorated']);

        $this->assertSame($secondDate, $rows[1]['due_date']);
        $this->assertSame($secondAmount, $rows[1]['amount']);
        $this->assertSame($prorated, $rows[1]['prorated']);

        $summary = BillingSchedule::summary($start, $cycle, 300.0);
        $this->assertSame($days, $summary['days_until_second_payment']);
        $this->assertSame($prorated, $summary['second_payment_prorated']);
        $this->assertSame($secondAmount, $summary['second_payment_amount']);
    }

    public function test_the_clients_worked_example_totals_ten_thousand_five_hundred_eighty(): void
    {
        $rows = BillingSchedule::build('2026-10-07', '15th', 300.0, 36);

        $this->assertCount(36, $rows);
        $this->assertSame(1058000, array_sum(array_column($rows, 'amount_cents'))); // $10,580, not $10,800
        $this->assertSame('2026-10-07', $rows[0]['due_date']);
        $this->assertSame('2026-10-15', $rows[1]['due_date']);
        $this->assertSame('2026-11-15', $rows[2]['due_date']);
        $this->assertSame('2029-08-15', $rows[35]['due_date']);
        $this->assertSame(30000, $rows[35]['amount_cents']); // no catch-up payment at the end
    }

    #[DataProvider('termProvider')]
    public function test_every_term_has_exactly_that_many_payments_and_only_the_second_can_be_prorated(int $term): void
    {
        $rows = BillingSchedule::build('2026-10-07', '15th', 300.0, $term);

        $this->assertCount($term, $rows);
        $this->assertSame(range(1, $term), array_column($rows, 'sequence'));
        foreach ($rows as $row) {
            $this->assertSame($row['sequence'] === 2, $row['prorated']);
        }
        // Every payment after the second falls on the 15th, a month apart.
        foreach (array_slice($rows, 1) as $row) {
            $this->assertSame('15', substr($row['due_date'], 8, 2));
        }
    }

    /** @return array<string, array{0: int}> */
    public static function termProvider(): array
    {
        return ['12 months' => [12], '24 months' => [24], '36 months' => [36]];
    }

    public function test_a_prorated_amount_rounds_half_up_to_the_cent(): void
    {
        // 214.00 x 8 / 30 = 57.0666... -> 57.07
        $this->assertSame(57.07, BillingSchedule::build('2026-10-07', '15th', 214.0, 12)[1]['amount']);
        // 100.00 x 1 / 30 = 3.3333 -> 3.33 ; 100.50 x 1 / 30 = 3.35 exactly
        $this->assertSame(3.33, BillingSchedule::build('2026-10-14', '15th', 100.0, 12)[1]['amount']);
        $this->assertSame(3.35, BillingSchedule::build('2026-10-14', '15th', 100.5, 12)[1]['amount']);
    }

    public function test_a_pickup_late_on_the_14th_in_texas_is_dated_the_14th(): void
    {
        // 8:30pm Central on Oct 14 is already Oct 15 in UTC. The date the customer lived is the 14th.
        $start = Carbon::parse('2026-10-14 20:30:00', 'America/Chicago');

        $rows = BillingSchedule::build($start, '15th', 300.0, 12);

        $this->assertSame('2026-10-14', $rows[0]['due_date']);
        $this->assertSame('2026-10-15', $rows[1]['due_date']);
        $this->assertSame(10.0, $rows[1]['amount']); // one day, not "start on the cycle day"
    }

    public function test_the_cycle_is_never_anything_but_the_first_or_the_fifteenth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BillingSchedule::build('2026-10-07', '20th', 300.0, 12);
    }

    public function test_cycle_labels(): void
    {
        $this->assertSame('the 1st of each month', BillingSchedule::cycleLabel('1st'));
        $this->assertSame('the 15th of each month', BillingSchedule::cycleLabel('15th'));
        $this->assertSame('To be selected before signing', BillingSchedule::cycleLabel(null));
    }
}

<?php

namespace App\Services;

use App\Models\LeaseAgreement;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Client (Joel), 2026-10-05: the permanent two-cycle billing structure.
 *
 *  - The customer chooses the 1st or the 15th of each month. No custom dates.
 *  - First payment: a full month, due on the start date (the pickup day).
 *  - Second payment: due on the next cycle date (the next upcoming 1st/15th,
 *    strictly after the start date). If that date is fewer than 30 days away
 *    it is prorated: (monthly / 30) x days until that date. Otherwise full.
 *  - Every later payment: the full monthly amount on the cycle date.
 *  - The lease ends after `term_months` payments in total. The prorated one
 *    counts as a payment and there is NO catch-up payment (so the total paid
 *    is slightly under term x monthly; the client confirmed this is
 *    intentional).
 *
 * Pure date/money arithmetic with no database access, so every edge case
 * (February, a start on the cycle day, a 31-day gap) is unit-testable.
 * Dates are handled as calendar dates in UTC internally so a daylight-saving
 * change can never turn a whole number of days into a fraction; callers pass
 * the date in the CLIENT's time zone (see BillingClock).
 */
class BillingSchedule
{
    public const CYCLE_FIRST = '1st';

    public const CYCLE_FIFTEENTH = '15th';

    public const CYCLES = [self::CYCLE_FIRST, self::CYCLE_FIFTEENTH];

    /** The client's proration rule uses a flat 30-day month. */
    public const PRORATION_MONTH_DAYS = 30;

    public static function cycleDay(string $cycle): int
    {
        return match ($cycle) {
            self::CYCLE_FIRST => 1,
            self::CYCLE_FIFTEENTH => 15,
            default => throw new \InvalidArgumentException("Unknown billing cycle: {$cycle}"),
        };
    }

    public static function cycleLabel(?string $cycle): string
    {
        return match ($cycle) {
            self::CYCLE_FIRST => 'the 1st of each month',
            self::CYCLE_FIFTEENTH => 'the 15th of each month',
            default => 'To be selected before signing',
        };
    }

    /** The next upcoming cycle date, strictly AFTER the start date (a start on the cycle day itself points to next month). */
    public static function nextCycleDate(string|CarbonInterface $startDate, string $cycle): Carbon
    {
        $start = self::asDate($startDate);
        $day = self::cycleDay($cycle);

        $candidate = $start->copy()->startOfMonth()->day($day);
        if ($candidate->lte($start)) {
            $candidate = $start->copy()->startOfMonth()->addMonthNoOverflow()->day($day);
        }

        return $candidate;
    }

    /**
     * Every payment row of the lease, in order.
     *
     * @return array<int, array{sequence: int, due_date: string, amount_cents: int, amount: float, prorated: bool}>
     */
    public static function build(string|CarbonInterface $startDate, string $cycle, float $monthlyAmount, int $termMonths): array
    {
        $start = self::asDate($startDate);
        $monthlyCents = (int) round($monthlyAmount * 100);
        $next = self::nextCycleDate($start, $cycle);
        $daysToSecond = (int) $start->diffInDays($next, true);

        $rows = [];
        for ($sequence = 1; $sequence <= $termMonths; $sequence++) {
            $due = $sequence === 1 ? $start->copy() : $next->copy()->addMonthsNoOverflow($sequence - 2);
            $cents = $monthlyCents;
            $prorated = false;

            if ($sequence === 2 && $daysToSecond < self::PRORATION_MONTH_DAYS) {
                $cents = self::proratedCents($monthlyCents, $daysToSecond);
                $prorated = true;
            }

            $rows[] = [
                'sequence' => $sequence,
                'due_date' => $due->toDateString(),
                'amount_cents' => $cents,
                'amount' => $cents / 100.0,
                'prorated' => $prorated,
            ];
        }

        return $rows;
    }

    /**
     * The first two payments for one cycle, for showing a customer what they
     * are about to choose (before pickup the real start date is unknown, so
     * callers pass an assumed one such as today).
     *
     * @return array{cycle: string, first_payment_date: string, first_payment_amount: float, second_payment_date: string, second_payment_amount: float, second_payment_prorated: bool, days_until_second_payment: int, recurring_amount: float, recurring_day: int, first_payment_card: float, second_payment_card: float, recurring_card: float}
     */
    public static function summary(string|CarbonInterface $startDate, string $cycle, float $monthlyAmount): array
    {
        $start = self::asDate($startDate);
        $monthlyCents = (int) round($monthlyAmount * 100);
        $next = self::nextCycleDate($start, $cycle);
        $days = (int) $start->diffInDays($next, true);
        $prorated = $days < self::PRORATION_MONTH_DAYS;
        $secondCents = $prorated ? self::proratedCents($monthlyCents, $days) : $monthlyCents;

        return [
            'cycle' => $cycle,
            'first_payment_date' => $start->toDateString(),
            'first_payment_amount' => $monthlyCents / 100.0,
            'second_payment_date' => $next->toDateString(),
            'second_payment_amount' => $secondCents / 100.0,
            'second_payment_prorated' => $prorated,
            'days_until_second_payment' => $days,
            'recurring_amount' => $monthlyCents / 100.0,
            'recurring_day' => self::cycleDay($cycle),
            // Dual pricing (client, 2026-10-05): what the same payments cost by card.
            'first_payment_card' => CardPricing::cardCents($monthlyCents) / 100.0,
            'second_payment_card' => CardPricing::cardCents($secondCents) / 100.0,
            'recurring_card' => CardPricing::cardCents($monthlyCents) / 100.0,
        ];
    }

    /** Both cycles side by side, as if the customer picked up equipment on $assumedStart (default: today in the client's time zone). */
    public static function illustrate(LeaseAgreement $lease, ?string $assumedStart = null): array
    {
        $start = $assumedStart ?? BillingClock::todayDate();
        $monthly = $lease->totalMonthlyPayment();

        return collect(self::CYCLES)
            ->mapWithKeys(fn (string $cycle) => [$cycle => self::summary($start, $cycle, $monthly)])
            ->all();
    }

    /** round-half-up of monthly / 30 x days, in whole cents (integer math, no float drift). */
    private static function proratedCents(int $monthlyCents, int $days): int
    {
        return intdiv($monthlyCents * $days + intdiv(self::PRORATION_MONTH_DAYS, 2), self::PRORATION_MONTH_DAYS);
    }

    /** A calendar date as midnight UTC, whatever time zone the input carried. */
    private static function asDate(string|CarbonInterface $value): Carbon
    {
        $dateString = $value instanceof CarbonInterface ? $value->toDateString() : Carbon::parse($value)->toDateString();

        return Carbon::parse($dateString, 'UTC')->startOfDay();
    }
}

<?php

namespace App\Services;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use Carbon\Carbon;

/**
 * Milestone 2 — Lease & Ownership Engine. Mirrors the signed contract's
 * pricing rules exactly (Section 2 "Full-Term Ownership" / Section 3
 * "Rental-Purchase Ownership") and the frontend's
 * `frontend/src/lib/sample-lease.ts` / wizard `types.ts` formulas, so the
 * admin wizard preview and the persisted, billed lease always agree.
 */
class LeaseEngine
{
    private const EPO_NINETY_DAY_MONTH_CUTOFF = 3;

    /**
     * Early Purchase Option payoff at a given month of the term.
     *
     * Within the first 90 days (~3 monthly cycles): Cash Price minus
     * payments made to date. After that: Cash Price minus 50% of payments
     * scheduled to date, plus payments still owed, plus any additional
     * funds. Taxes are due separately when the EPO is exercised — not part
     * of this number. At/after the final month the customer already owns
     * the unit via the full-term path, so EPO is 0.
     */
    public static function epoAt(LeaseAgreement $lease, int $month): float
    {
        $term = (int) $lease->term_months;
        $monthlyRental = (float) $lease->monthly_rental_payment;
        $cashPrice = (float) $lease->cash_price;
        $additionalFunds = (float) $lease->additional_funds;

        $m = max(0, min($term, $month));
        if ($term <= 0 || $m >= $term) {
            return 0.0;
        }

        $paymentsToDate = $m * $monthlyRental;

        if ($m <= self::EPO_NINETY_DAY_MONTH_CUTOFF) {
            return round(max(0, $cashPrice - $paymentsToDate), 2);
        }

        $stillOwed = ($term - $m) * $monthlyRental;

        return round(max(0, $cashPrice - 0.5 * $paymentsToDate + $stillOwed + $additionalFunds), 2);
    }

    /** EPO price for every month of the term (1..term-1), plus month `term` at 0. */
    public static function fullSchedule(LeaseAgreement $lease): array
    {
        $term = (int) $lease->term_months;
        $schedule = [];
        for ($month = 1; $month <= $term; $month++) {
            $schedule[] = ['month' => $month, 'value' => self::epoAt($lease, $month)];
        }

        return $schedule;
    }

    /** EPO price at the lease's current position (based on payments actually marked paid). */
    public static function epoToday(LeaseAgreement $lease): float
    {
        return self::epoAt($lease, max(1, $lease->paymentsMadeCount()));
    }

    public static function totalRentalPurchasePrice(float $monthlyRental, int $termMonths): float
    {
        return round($monthlyRental * $termMonths, 2);
    }

    /**
     * Generates the full monthly payment schedule for a lease, one row per
     * term month, starting the cycle after the lease's start date. Safe to
     * call only once per lease — callers should check `payments()->count()`
     * first so re-triggering a status change doesn't duplicate rows.
     */
    public static function generatePaymentSchedule(LeaseAgreement $lease): void
    {
        if ($lease->payments()->exists()) {
            return;
        }

        $amount = $lease->totalMonthlyPayment();
        $dueDate = Carbon::parse($lease->start_date);

        for ($month = 1; $month <= (int) $lease->term_months; $month++) {
            $dueDate = $dueDate->copy()->addMonthNoOverflow();

            Payment::create([
                'lease_agreement_id' => $lease->id,
                'amount' => $amount,
                'due_date' => $dueDate,
                'status' => Payment::STATUS_PENDING,
            ]);
        }
    }

    /**
     * Keeps `rental_payments_paid_to_date` (a persisted, human-readable
     * dollar figure on the lease) in sync with the count of payments
     * actually marked paid. Call after any payment status change.
     */
    public static function syncPaymentsPaidToDate(LeaseAgreement $lease): void
    {
        $paidCount = $lease->paymentsMadeCount();
        $lease->update([
            'rental_payments_paid_to_date' => round($paidCount * (float) $lease->monthly_rental_payment, 2),
        ]);

        if ($lease->term_months > 0 && $paidCount >= $lease->term_months) {
            $lease->update(['ownership_status' => LeaseAgreement::OWNERSHIP_OWNED]);
        }
    }
}

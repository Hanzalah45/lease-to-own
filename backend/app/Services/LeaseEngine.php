<?php

namespace App\Services;

use App\Models\AdminPermission;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\RiskRedFlag;
use App\Models\User;
use App\Notifications\ActivateAccountNotification;
use App\Notifications\PaymentStatusChangedNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

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

    /** Contract Section 8, "Late Fee": 10% of the missed Rental Payment, floored at $5, capped at $30. */
    public const LATE_FEE_RATE = 0.10;

    public const LATE_FEE_MIN = 5.0;

    public const LATE_FEE_MAX = 30.0;

    public const LATE_FEE_GRACE_DAYS = 10;

    /**
     * Early Purchase Option payoff at a given month of the term.
     *
     * Final formula (client, direct answer, 2026-09-05 — supersedes the
     * 2026-09-04 "full term scheduled" restatement, which produced an EPO
     * that could exceed the cash price and jump discontinuously at the
     * 90-day mark; this version doesn't): within the first 90 days (~3
     * monthly cycles), Cash Price minus all rental payments scheduled to
     * date (100% credit). After that: Cash Price minus 50% of rental
     * payments scheduled TO DATE (not the full term), plus any payments
     * still owed (amounts already past due and unpaid — $0 for a customer
     * current on payments; this only bites if they're behind), minus any
     * additional funds (extra amounts already paid in). The client was
     * explicit the security deposit does NOT reduce this — "it's
     * considered the cost of the loan." Taxes are due separately when the
     * EPO is exercised — not part of this number. At/after the final month
     * the customer already owns the unit via the full-term path, so EPO is 0.
     */
    public static function epoAt(LeaseAgreement $lease, int $month, float $amountPastDue = 0.0): float
    {
        $term = (int) $lease->term_months;
        $monthlyRental = (float) $lease->monthly_rental_payment;
        $cashPrice = (float) $lease->cash_price;
        $additionalFunds = (float) $lease->additional_funds;

        $m = max(0, min($term, $month));
        if ($term <= 0 || $m >= $term) {
            return 0.0;
        }

        $paymentsScheduledToDate = $m * $monthlyRental;

        if ($m <= self::EPO_NINETY_DAY_MONTH_CUTOFF) {
            return round(max(0, $cashPrice - $paymentsScheduledToDate), 2);
        }

        return round(max(0, $cashPrice - 0.5 * $paymentsScheduledToDate + $amountPastDue - $additionalFunds), 2);
    }

    /**
     * EPO price for every month of the term (1..term-1), plus month `term`
     * at 0 — a hypothetical projection "assuming on-time payments" (see the
     * chart's own caption), so amountPastDue is always 0 here. Real arrears
     * only apply to epoToday()'s actual current-state quote below.
     */
    public static function fullSchedule(LeaseAgreement $lease): array
    {
        $term = (int) $lease->term_months;
        $schedule = [];
        for ($month = 1; $month <= $term; $month++) {
            $schedule[] = ['month' => $month, 'value' => self::epoAt($lease, $month)];
        }

        return $schedule;
    }

    /**
     * EPO price at the lease's current position (based on payments actually
     * marked paid), with real "still owed" arrears — any payment past its
     * due date and not yet marked paid — folded in per the formula above.
     */
    public static function epoToday(LeaseAgreement $lease): float
    {
        // Same relationLoaded guard as paymentsMadeCount() — callers that
        // list many leases eager-load payments once up front (see
        // Customer\ApplicationController::index()); querying via payments()
        // here instead of filtering the loaded collection turned one list
        // request into an extra query per row (real regression, caught by
        // ApplicationListQueryCountTest).
        $today = now()->startOfDay();
        // Excludes a stuck/declined deposit charge (real bug fixed
        // 2026-10-01, before it could ever fire) — a deposit isn't part of
        // the rent-to-own payoff schedule's arrears, so a failed deposit
        // charge must never inflate the EPO price. Late fees stay included
        // here deliberately — unlike paymentsMadeCount(), an overdue late
        // fee genuinely is still owed toward payoff.
        $isPastDueUnpaid = fn (Payment $p) => $p->status !== Payment::STATUS_PAID && $p->type !== Payment::TYPE_DEPOSIT && $p->due_date && $p->due_date->lte($today);

        $amountPastDue = $lease->relationLoaded('payments')
            ? (float) $lease->payments->filter($isPastDueUnpaid)->sum('amount')
            : (float) $lease->payments()->where('status', '!=', Payment::STATUS_PAID)->where('type', '!=', Payment::TYPE_DEPOSIT)->whereDate('due_date', '<=', $today)->sum('amount');

        return self::epoAt($lease, max(1, $lease->paymentsMadeCount()), $amountPastDue);
    }

    public static function totalRentalPurchasePrice(float $monthlyRental, int $termMonths): float
    {
        return round($monthlyRental * $termMonths, 2);
    }

    /** Late fee owed on a single overdue rental payment (contract Section 8, "Late Fee"). */
    public static function lateFeeFor(Payment $payment): float
    {
        $raw = (float) $payment->amount * self::LATE_FEE_RATE;

        return round(min(self::LATE_FEE_MAX, max(self::LATE_FEE_MIN, $raw)), 2);
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
     * Rebuilds the payment schedule from the lease's current terms — used
     * when term/rental terms are edited after the schedule was already
     * generated (post-approval, pre-signature). Refuses once a payment has
     * actually been marked paid, since at that point money has moved against
     * the old numbers and the schedule can no longer be silently replaced.
     */
    public static function regeneratePaymentSchedule(LeaseAgreement $lease): void
    {
        if ($lease->payments()->where('status', Payment::STATUS_PAID)->exists()) {
            throw new \RuntimeException('Cannot regenerate the payment schedule once a payment has been made.');
        }

        $lease->payments()->delete();
        self::generatePaymentSchedule($lease);
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

        // Reversible both ways: an admin correcting a mistaken final "paid" mark
        // back to failed/pending after the lease reached OWNED must not leave it
        // permanently marked as owned with too few payments on record.
        $ownershipStatus = ($lease->term_months > 0 && $paidCount >= $lease->term_months)
            ? LeaseAgreement::OWNERSHIP_OWNED
            : LeaseAgreement::OWNERSHIP_LEASING;

        if ($lease->ownership_status !== $ownershipStatus) {
            $lease->update(['ownership_status' => $ownershipStatus]);
        }
    }

    /**
     * Marks a lease's earliest still-pending payment as paid. Used where a
     * payment is recorded as a side effect of an admin action other than
     * PaymentController::update() itself — e.g. "Mark Delivered & Paid",
     * which advances the application to "finished" and is meant to record
     * that first payment landed, not just relabel the status.
     */
    public static function markFirstPaymentPaid(LeaseAgreement $lease, int $recordedBy): ?Payment
    {
        $payment = $lease->payments()->where('status', Payment::STATUS_PENDING)->orderBy('due_date')->first();
        if (! $payment) {
            return null;
        }

        $payment->update([
            'status' => Payment::STATUS_PAID,
            'paid_date' => now()->toDateString(),
            'recorded_by' => $recordedBy,
        ]);

        self::syncPaymentsPaidToDate($lease);

        return $payment;
    }

    /**
     * Applies a payment status change with every side effect that has to
     * follow it — risk flagging on a failure, staff/customer notifications,
     * paid-to-date sync, and guest account activation on a first payment —
     * regardless of whether the change was made by an admin
     * (Admin\PaymentController::update()) or arrived automatically from a
     * payment processor webhook (StripeWebhookController). $recordedBy is
     * null for the latter, since no admin performed the action.
     */
    public static function applyPaymentStatusChange(Payment $payment, string $status, ?string $method, ?int $recordedBy): Payment
    {
        $wasFailed = $payment->status === Payment::STATUS_FAILED;
        $wasPaid = $payment->status === Payment::STATUS_PAID;

        $payment->update([
            'status' => $status,
            'method' => $method ?? $payment->method,
            'paid_date' => $status === Payment::STATUS_PAID ? now()->toDateString() : $payment->paid_date,
            'recorded_by' => $recordedBy ?? $payment->recorded_by,
        ]);

        if ($status === Payment::STATUS_FAILED && ! $wasFailed) {
            RiskRedFlagger::flag(
                $payment->leaseAgreement->customer_id,
                RiskRedFlag::TYPE_FAILED_ACH,
                sprintf(
                    '%s payment of $%s (due %s) was marked failed.',
                    $payment->method ? strtoupper($payment->method) : 'A',
                    number_format((float) $payment->amount, 2),
                    $payment->due_date?->toDateString() ?? 'unknown date',
                ),
                $payment,
            );
        }

        // Staff are notified on both a failure and a successful payment landing —
        // only on the transition into that status, not on every re-save.
        $enteredFailed = $status === Payment::STATUS_FAILED && ! $wasFailed;
        $enteredPaid = $status === Payment::STATUS_PAID && ! $wasPaid;
        if ($enteredFailed || $enteredPaid) {
            $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
                ->orWhere(function ($query) {
                    $query->where('role', User::ROLE_ADMIN)
                        ->where(function ($inner) {
                            $inner->whereDoesntHave('adminPermissions')
                                ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::PAYMENT_TRACKING));
                        });
                })->get();
            Notification::send($recipients, new PaymentStatusChangedNotification($payment));
        }

        self::syncPaymentsPaidToDate($payment->leaseAgreement);

        // A successful deposit charge (client, Joel, 2026-10-01) converges
        // with the existing manual "Mark Deposit Received" admin action —
        // Admin\ApplicationController's unconditional set of this same flag
        // becomes a no-op once Stripe already did it, and stays the full
        // fallback (cash/check, a failed charge) exactly as before when it
        // hasn't. Never cleared on a failed/refunded charge — a prior
        // manual "received" stays received.
        if ($enteredPaid && $payment->type === Payment::TYPE_DEPOSIT) {
            $payment->leaseAgreement->application?->update(['deposit_received' => true]);
        }

        // Pickup/first-payment account setup (client, 2026-09-04) — see
        // activateGuestAccountIfFirstPayment() below, also used by the
        // "Mark Delivered & Paid" path (ApplicationController::update()).
        if ($enteredPaid) {
            self::activateGuestAccountIfFirstPayment($payment->leaseAgreement);
        }

        if (in_array($status, [Payment::STATUS_PAID, Payment::STATUS_FAILED, Payment::STATUS_REFUNDED], true)) {
            $customer = $payment->leaseAgreement->customer;
            if ($customer->customerProfile?->payment_reminder_emails ?? true) {
                $customer->notify(new PaymentStatusChangedNotification($payment));
            }
        }

        return $payment->fresh();
    }

    /**
     * Pickup/first-payment account setup (client, 2026-09-04): a
     * guest-originated customer's shadow account has no usable password
     * until they set one via this signed link, sent the moment their first
     * payment lands — wherever that happens. Shared by
     * PaymentController::update() and the "Mark Delivered & Paid" path so
     * neither one is the only place this fires.
     */
    public static function activateGuestAccountIfFirstPayment(LeaseAgreement $lease): void
    {
        $customer = $lease->customer;
        if ($customer->status === 'pending' && $lease->paymentsMadeCount() === 1) {
            $customer->notify(new ActivateAccountNotification(AccountSetupSigner::urlFor($customer)));
        }
    }
}

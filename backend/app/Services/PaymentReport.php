<?php

namespace App\Services;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Money actually collected, by the day it was recorded as paid, with the sales tax inside it (client, Joel, 2026-10-16: "as we
 * collect sales tax, we will need the correct reports for sales tax", the signed plan's financial reporting).
 *
 * Where the tax is: a monthly payment is the rent plus the LDW plus the sales tax on those two (LeaseAgreement::totalMonthlyPayment),
 * so a payment carries that share of tax whatever its size (a prorated payment carries a proportional part). The tracking fee, a
 * security deposit, a late fee and an extra payment carry none. The tracking fee and first month is one charge, and the first
 * monthly row that "Mark Delivered" records alongside it is only its mirror: only the charge is counted, never both.
 * A card fee is kept apart, it is neither rent nor taxed.
 */
class PaymentReport
{
    /**
     * One row per payment collected between the two dates, oldest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Carbon $from, Carbon $to): Collection
    {
        $payments = Payment::with([
            'leaseAgreement.customer:id,name,email',
            'leaseAgreement.payments:id,lease_agreement_id,type,status,due_date,stripe_payment_intent_id',
        ])
            ->where('status', Payment::STATUS_PAID)
            ->whereBetween('paid_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('paid_date')
            ->orderBy('id')
            ->get();

        return $payments
            ->reject(fn (Payment $p) => $this->isFirstMonthMirror($p))
            ->map(fn (Payment $p) => $this->row($p))
            ->values();
    }

    /**
     * Totals by month (the day it was recorded as paid) and by payment method.
     *
     * @return array{months: list<array<string, mixed>>, totals: array<string, float>, by_method: array<string, float>}
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $rows = $this->rows($from, $to);
        $keys = ['taxable_sales', 'sales_tax', 'tracking_fees', 'deposits', 'late_fees', 'extra_payments', 'card_fees', 'total_collected'];

        $months = [];
        foreach ($rows->groupBy(fn ($r) => substr($r['paid_date'], 0, 7)) as $month => $group) {
            $line = ['month' => $month, 'label' => Carbon::createFromFormat('Y-m', $month)->format('F Y'), 'payments' => $group->count()];
            foreach ($keys as $key) {
                $line[$key] = round((float) $group->sum($key), 2);
            }
            $months[] = $line;
        }

        $totals = ['payments' => (float) $rows->count()];
        foreach ($keys as $key) {
            $totals[$key] = round((float) $rows->sum($key), 2);
        }

        return [
            'months' => $months,
            'totals' => $totals,
            'by_method' => $rows->groupBy(fn ($r) => $r['method'] ?? 'unknown')
                ->map(fn ($group) => round((float) $group->sum('total_collected'), 2))
                ->all(),
        ];
    }

    /**
     * How one collected payment splits: the sales tax and taxable sales inside it, the tracking fee, the card fee. The books
     * (QuickbooksJournal) use the same split, so the report and QuickBooks always agree.
     *
     * @return array<string, mixed>
     */
    public function breakdown(Payment $payment): array
    {
        return $this->row($payment);
    }

    /** @return array<string, mixed> */
    private function row(Payment $payment): array
    {
        $lease = $payment->leaseAgreement;
        $amount = round((float) $payment->amount, 2);
        $fee = round((float) $payment->card_fee_amount, 2);
        $tax = 0.0;
        $taxable = 0.0;
        $tracking = 0.0;

        if ($payment->type === Payment::TYPE_RENTAL) {
            $tax = $this->taxShare($lease, $amount);
            $taxable = round($amount - $tax, 2);
        } elseif ($payment->type === Payment::TYPE_PICKUP_BALANCE) {
            // The $150 tracking fee plus the first month: the first month carries the tax.
            $tracking = min(LeaseAgreement::TRACKING_DEVICE_FEE, $amount);
            $tax = $this->taxShare($lease, round($amount - $tracking, 2));
            $taxable = round($amount - $tracking - $tax, 2);
        }

        return [
            'paid_date' => $payment->paid_date->toDateString(),
            'customer' => $lease?->customer?->name,
            'customer_email' => $lease?->customer?->email,
            'lease_id' => $payment->lease_agreement_id,
            'type' => $payment->type,
            'method' => $payment->method,
            'amount' => $amount,
            'taxable_sales' => $taxable,
            'sales_tax' => $tax,
            'tracking_fees' => round($tracking, 2),
            'deposits' => $payment->type === Payment::TYPE_DEPOSIT ? $amount : 0.0,
            'late_fees' => $payment->type === Payment::TYPE_LATE_FEE ? $amount : 0.0,
            'extra_payments' => $payment->type === Payment::TYPE_EXTRA_PAYMENT ? $amount : 0.0,
            'card_fees' => $fee,
            'total_collected' => round($amount + $fee, 2),
        ];
    }

    /** The sales tax inside an amount paid toward the monthly payment (rent + LDW + tax): the lease's own tax share. */
    private function taxShare(?LeaseAgreement $lease, float $amount): float
    {
        if (! $lease) {
            return 0.0;
        }
        $total = $lease->totalMonthlyPayment();
        if ($total <= 0) {
            return 0.0;
        }

        return round($amount * ($lease->salesTaxAmount() / $total), 2);
    }

    /**
     * The first monthly row of a lease whose first month was collected inside a tracking fee and first month charge: it is
     * recorded as paid without any money moving, so counting it as well as the charge would count the month twice.
     */
    public function isFirstMonthMirror(Payment $payment): bool
    {
        if ($payment->type !== Payment::TYPE_RENTAL || $payment->stripe_payment_intent_id) {
            return false;
        }
        $siblings = $payment->leaseAgreement?->payments ?? collect();
        $charged = $siblings->contains(fn (Payment $p) => $p->type === Payment::TYPE_PICKUP_BALANCE
            && ($p->status === Payment::STATUS_PAID || $p->stripe_payment_intent_id));
        if (! $charged) {
            return false;
        }

        $first = $siblings
            ->filter(fn (Payment $p) => $p->type === Payment::TYPE_RENTAL && $p->status === Payment::STATUS_PAID && ! $p->stripe_payment_intent_id)
            ->sortBy(fn (Payment $p) => [$p->due_date?->toDateString(), $p->id])
            ->first();

        return $first?->id === $payment->id;
    }
}

<?php

namespace App\Services;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentRefund;
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
 *
 * Money that went back (a refund, a lost chargeback) is a row of its own on the day it went back, with every figure negative and
 * split the same way as the payment it refunds, so the months add up to what was actually kept.
 */
class PaymentReport
{
    /** The parts a payment (and so a refund of it) splits into. */
    public const PARTS = ['taxable_sales', 'sales_tax', 'tracking_fees', 'deposits', 'late_fees', 'extra_payments', 'card_fees'];

    /**
     * One row per payment collected between the two dates, and one per refund made between them, oldest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Carbon $from, Carbon $to): Collection
    {
        $payments = Payment::with([
            'leaseAgreement.customer:id,name,email',
            'leaseAgreement.payments:id,lease_agreement_id,type,status,due_date,stripe_payment_intent_id',
            'parts',
        ])
            // A payment that was refunded stays a collected payment, its refund is the row that takes it back out.
            ->where(fn ($q) => $q->where('status', Payment::STATUS_PAID)
                ->orWhere(fn ($r) => $r->where('status', Payment::STATUS_REFUNDED)->whereHas('refunds', fn ($f) => $f->where('status', PaymentRefund::STATUS_SUCCEEDED))))
            ->whereBetween('paid_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('paid_date')
            ->orderBy('id')
            ->get();

        $paymentRows = $payments
            ->reject(fn (Payment $p) => $this->isFirstMonthMirror($p))
            ->map(fn (Payment $p) => $this->row($p));

        // The days are the business's own, the refund moment is stored in UTC.
        $start = Carbon::parse($from->toDateString(), BillingClock::TIMEZONE)->startOfDay()->utc();
        $end = Carbon::parse($to->toDateString(), BillingClock::TIMEZONE)->endOfDay()->utc();
        $refundRows = PaymentRefund::with([
            'part',
            'payment.leaseAgreement.customer:id,name,email',
            'payment.leaseAgreement.payments:id,lease_agreement_id,type,status,due_date,stripe_payment_intent_id',
        ])
            ->where('status', PaymentRefund::STATUS_SUCCEEDED)
            // A part taken back from a payment that never became paid was never counted, so its refund is not either.
            ->whereHas('payment', fn ($q) => $q->whereIn('status', [Payment::STATUS_PAID, Payment::STATUS_REFUNDED]))
            ->whereBetween('refunded_at', [$start, $end])
            ->orderBy('refunded_at')
            ->orderBy('id')
            ->get()
            ->reject(fn (PaymentRefund $r) => $this->isFirstMonthMirror($r->payment))
            ->map(fn (PaymentRefund $r) => $this->refundRow($r));

        return $paymentRows->concat($refundRows)->sortBy('paid_date')->values();
    }

    /**
     * Totals by month (the day it was recorded as paid, or refunded) and by payment method. Refunds are taken off, so these are
     * the figures that were kept.
     *
     * @return array{months: list<array<string, mixed>>, totals: array<string, float>, by_method: array<string, float>}
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $rows = $this->rows($from, $to);
        $keys = [...self::PARTS, 'rent', 'ldw', 'total_collected'];

        $months = [];
        foreach ($rows->groupBy(fn ($r) => substr($r['paid_date'], 0, 7)) as $month => $group) {
            $line = [
                'month' => $month,
                'label' => Carbon::createFromFormat('Y-m', $month)->format('F Y'),
                'payments' => $group->where('kind', 'payment')->count(),
                'refunds' => $group->where('kind', 'refund')->count(),
                'refunded' => round(-1 * (float) $group->where('kind', 'refund')->sum('total_collected'), 2),
            ];
            foreach ($keys as $key) {
                $line[$key] = round((float) $group->sum($key), 2);
            }
            $months[] = $line;
        }

        $totals = [
            'payments' => (float) $rows->where('kind', 'payment')->count(),
            'refunds' => (float) $rows->where('kind', 'refund')->count(),
            'refunded' => round(-1 * (float) $rows->where('kind', 'refund')->sum('total_collected'), 2),
        ];
        foreach ($keys as $key) {
            $totals[$key] = round((float) $rows->sum($key), 2);
        }

        return [
            'months' => $months,
            'totals' => $totals,
            // A payment paid in parts is counted by the method of each part, not as one "split" amount.
            'by_method' => $rows
                ->flatMap(fn ($r) => $r['method_parts'] ?: [['method' => $r['method'] ?? 'unknown', 'total' => $r['total_collected']]])
                ->groupBy(fn ($p) => $p['method'] ?? 'unknown')
                ->map(fn ($group) => round((float) $group->sum('total'), 2))
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

    /**
     * How one refund splits, in the same parts as the payment it refunds, as positive amounts. A refund takes its share of every part;
     * the refunds of a payment are cut from the whole in the order they were made, so they add up to exactly the payment when it is
     * refunded in full (no cent is lost to rounding, and none refunded twice).
     *
     * @return array<string, float> the parts, plus total_collected
     */
    public function refundBreakdown(PaymentRefund $refund): array
    {
        $payment = $refund->payment;
        $parts = $this->row($payment);
        $weights = [];
        foreach (self::PARTS as $key) {
            $weights[$key] = $this->cents($parts[$key]);
        }
        $total = $this->cents($parts['total_collected']);

        $before = $this->cents(PaymentRefund::where('payment_id', $refund->payment_id)
            ->where('status', PaymentRefund::STATUS_SUCCEEDED)
            ->where('id', '<', $refund->id)
            ->sum('amount'));
        $after = $before + $this->cents($refund->amount);

        $upTo = $this->apportion($weights, min($after, $total));
        $prior = $this->apportion($weights, min($before, $total));

        $split = ['total_collected' => round($this->cents($refund->amount) / 100, 2)];
        foreach (self::PARTS as $key) {
            $split[$key] = round(($upTo[$key] - $prior[$key]) / 100, 2);
        }

        // The taxable sales that went back are cut into rent and LDW in the same way, against everything refunded so far.
        $inner = ['rent' => $this->cents($parts['rent']), 'ldw' => $this->cents($parts['ldw'])];
        $innerUpTo = $this->apportion($inner, $upTo['taxable_sales']);
        $innerPrior = $this->apportion($inner, $prior['taxable_sales']);
        foreach (['rent', 'ldw'] as $key) {
            $split[$key] = round(($innerUpTo[$key] - $innerPrior[$key]) / 100, 2);
        }

        return $split;
    }

    /**
     * Cuts $cents into the same proportions as $weights, to the cent, the leftover cents going to the largest remainders.
     *
     * @param  array<string, int>  $weights
     * @return array<string, int>
     */
    private function apportion(array $weights, int $cents): array
    {
        $sum = array_sum($weights);
        $out = array_fill_keys(array_keys($weights), 0);
        if ($sum <= 0 || $cents <= 0) {
            return $out;
        }

        $remainders = [];
        foreach ($weights as $key => $weight) {
            $out[$key] = intdiv($cents * $weight, $sum);
            $remainders[$key] = ($cents * $weight) % $sum;
        }

        $left = $cents - array_sum($out);
        arsort($remainders);
        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }
            $out[$key]++;
            $left--;
        }

        return $out;
    }

    private function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * How an amount paid toward a payment of this type splits (the same split a whole payment, a piece of one and a receipt use).
     *
     * @return array<string, float> the parts, plus amount (the bank price) and total_collected (with the card fee)
     */
    public function split(?LeaseAgreement $lease, string $type, float $amount, float $fee): array
    {
        $amount = round($amount, 2);
        $fee = round($fee, 2);
        $tax = 0.0;
        $taxable = 0.0;
        $tracking = 0.0;

        if ($type === Payment::TYPE_RENTAL) {
            $tax = $this->taxShare($lease, $amount);
            $taxable = round($amount - $tax, 2);
        } elseif ($type === Payment::TYPE_PICKUP_BALANCE) {
            // The $150 tracking fee plus the first month: the first month carries the tax.
            $tracking = min(LeaseAgreement::TRACKING_DEVICE_FEE, $amount);
            $tax = $this->taxShare($lease, round($amount - $tracking, 2));
            $taxable = round($amount - $tracking - $tax, 2);
        }

        // The taxable sales are the rent and the loss damage waiver (LDW), both taxed: told apart so the books can give the LDW its own account
        // (client, Joel, 2026-10-19 checklist, section 10). taxable_sales stays their sum, which is the figure the sales tax return needs.
        $ldw = $this->ldwShare($lease, $taxable);

        return [
            'amount' => $amount,
            'taxable_sales' => $taxable,
            'rent' => round($taxable - $ldw, 2),
            'ldw' => $ldw,
            'sales_tax' => $tax,
            'tracking_fees' => round($tracking, 2),
            'deposits' => $type === Payment::TYPE_DEPOSIT ? $amount : 0.0,
            'late_fees' => $type === Payment::TYPE_LATE_FEE ? $amount : 0.0,
            'extra_payments' => $type === Payment::TYPE_EXTRA_PAYMENT ? $amount : 0.0,
            'card_fees' => $fee,
            'total_collected' => round($amount + $fee, 2),
        ];
    }

    /** @return array<string, mixed> */
    private function row(Payment $payment): array
    {
        $lease = $payment->leaseAgreement;

        return [
            'kind' => 'payment',
            'paid_date' => $payment->paid_date->toDateString(),
            'customer' => $lease?->customer?->name,
            'customer_email' => $lease?->customer?->email,
            'lease_id' => $payment->lease_agreement_id,
            'type' => $payment->type,
            'method' => $payment->method,
        ] + $this->split($lease, $payment->type, (float) $payment->amount, (float) $payment->card_fee_amount) + [
            // What each piece brought in, when the payment was paid in pieces (the by-method totals use it).
            'method_parts' => $payment->relationLoaded('parts')
                ? $payment->parts->where('status', 'paid')->map(fn ($p) => ['method' => $p->method, 'total' => $p->totalCharged()])->values()->all()
                : [],
        ];
    }

    /** The row for money that went back: the day it did, and every figure negative. */
    private function refundRow(PaymentRefund $refund): array
    {
        $payment = $refund->payment;
        $lease = $payment->leaseAgreement;
        $split = $this->refundBreakdown($refund);

        $row = [
            'kind' => 'refund',
            'paid_date' => $refund->refunded_at->setTimezone(BillingClock::TIMEZONE)->toDateString(),
            'customer' => $lease?->customer?->name,
            'customer_email' => $lease?->customer?->email,
            'lease_id' => $payment->lease_agreement_id,
            'type' => $payment->type,
            'method' => $refund->part?->method ?? $payment->method,
            'method_parts' => [],
            // The bank price part of what went back: everything but the card fee.
            'amount' => round(-1 * ($split['total_collected'] - $split['card_fees']), 2),
            'total_collected' => round(-1 * $split['total_collected'], 2),
        ];
        foreach ([...self::PARTS, 'rent', 'ldw'] as $key) {
            // + 0.0 so a part of nothing reads 0, never -0.
            $row[$key] = round(-1 * $split[$key], 2) + 0.0;
        }

        return $row;
    }

    /** The loss damage waiver inside an amount of taxable sales (rent + LDW): the lease's own LDW share. */
    private function ldwShare(?LeaseAgreement $lease, float $taxable): float
    {
        if (! $lease || $taxable <= 0) {
            return 0.0;
        }
        $ldw = $lease->ldwMonthlyAmount();
        $before = (float) $lease->monthly_rental_payment + $ldw;

        return $ldw > 0 && $before > 0 ? round($taxable * ($ldw / $before), 2) : 0.0;
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

<?php

namespace App\Services;

use App\Models\AdditionalFundsEntry;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every change to a lease's Additional Funds goes through here (contract Section 19; client, Joel, 2026-10-19 checklist, section 3).
 * The balance on the lease and the entries are changed together under a lock on the lease, so the balance is always the sum of the
 * entries and a double press or two events at once cannot take it below zero or count a movement twice.
 */
class AdditionalFundsLedger
{
    /**
     * Moves the balance by $delta (positive: money in, negative: money out) and records why. The balance never goes below zero: a
     * movement that would is cut to what is there. Returns null when nothing moved.
     *
     * @param  array{payment?: ?Payment, refund?: ?PaymentRefund, description?: ?string, user?: ?User}  $context
     */
    public static function apply(LeaseAgreement $lease, string $kind, float $delta, array $context = []): ?AdditionalFundsEntry
    {
        return DB::transaction(function () use ($lease, $kind, $delta, $context) {
            $locked = LeaseAgreement::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            $before = round((float) $locked->additional_funds, 2);
            $after = round(max(0.0, $before + $delta), 2);
            $moved = round($after - $before, 2);
            if (abs($moved) < 0.005) {
                return null;
            }

            $locked->update(['additional_funds' => $after]);
            // The lease the caller holds shows the new balance too.
            $lease->setAttribute('additional_funds', $after);
            $lease->syncOriginalAttribute('additional_funds');

            return AdditionalFundsEntry::create([
                'lease_agreement_id' => $locked->id,
                'payment_id' => ($context['payment'] ?? null)?->id,
                'payment_refund_id' => ($context['refund'] ?? null)?->id,
                'kind' => $kind,
                'amount' => $moved,
                'balance_after' => $after,
                'description' => $context['description'] ?? null,
                'created_by' => ($context['user'] ?? null)?->id,
            ]);
        });
    }

    /**
     * What is held for the customer and not already on its way back: the balance, less the part of refunds still waiting for the bank
     * (a bank refund only comes off the balance once the bank confirms it).
     */
    public static function available(LeaseAgreement $lease): float
    {
        $pending = 0.0;
        $refunds = PaymentRefund::with('payment')
            ->where('status', PaymentRefund::STATUS_PENDING)
            ->whereHas('payment', fn ($q) => $q->where('lease_agreement_id', $lease->id)->where('type', Payment::TYPE_EXTRA_PAYMENT))
            ->get();
        foreach ($refunds as $refund) {
            $pending += self::principalShare($refund->payment, (float) $refund->amount);
        }

        return round(max(0.0, (float) $lease->additional_funds - $pending), 2);
    }

    /** The part of a refund of an extra payment that came out of the Additional Funds: its own amount, not the card fee. */
    public static function principalShare(Payment $payment, float $refundAmount): float
    {
        $total = $payment->totalCharged();

        return $total > 0 ? round($refundAmount * ((float) $payment->amount / $total), 2) : 0.0;
    }
}

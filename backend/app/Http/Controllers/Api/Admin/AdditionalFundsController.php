<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdditionalFundsEntry;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\AdditionalFundsLedger;
use App\Services\AuditLog;
use App\Services\LeaseEngine;
use App\Services\PaymentRefunder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The money a customer has paid on top of the monthly payments toward the early purchase price (contract Section 19, "Additional
 * Funds"): its full history, applying it to the cost of repairs when the equipment comes back, and giving what is left back
 * (client, Joel, 2026-10-19 checklist, section 3).
 */
class AdditionalFundsController extends Controller
{
    public function show(LeaseAgreement $leaseAgreement)
    {
        return response()->json(['data' => $this->present($leaseAgreement)]);
    }

    /** Applies part or all of the balance to what repairs or service cost (contract Section 19: repairs first, only the rest is refundable). */
    public function applyToRepairs(Request $request, LeaseAgreement $leaseAgreement)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            // What the repair was, kept with the entry and shown to the customer.
            'description' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        $admin = $request->user();
        $amount = round((float) $data['amount'], 2);

        $entry = null;
        DB::transaction(function () use ($leaseAgreement, $amount, $data, $admin, &$entry) {
            $lease = LeaseAgreement::with(['application', 'contract'])->whereKey($leaseAgreement->id)->lockForUpdate()->firstOrFail();
            abort_if($reason = $this->repairsBlocker($lease), 422, $reason);

            $available = AdditionalFundsLedger::available($lease);
            abort_if($amount > $available, 422, sprintf('There is only $%s of Additional Funds that can still be applied.', number_format($available, 2)));

            $entry = AdditionalFundsLedger::apply($lease, AdditionalFundsEntry::KIND_REPAIRS, -1 * $amount, [
                'user' => $admin, 'description' => 'Applied to repairs: '.trim($data['description']),
            ]);

            $lease->application?->dealerNotes()->create([
                'author_user_id' => $admin->id,
                'text' => sprintf('$%s of Additional Funds was applied to repairs (%s). Entered by %s.', number_format($amount, 2), trim($data['description']), $admin->name),
            ]);
        });

        AuditLog::record('funds.applied_to_repairs', sprintf('$%s of Additional Funds was applied to repairs: %s', number_format($amount, 2), trim($data['description'])), [
            'lease' => $leaseAgreement, 'amount' => $amount, 'details' => ['entry_id' => $entry?->id],
        ]);

        return response()->json(['data' => $this->present($leaseAgreement->fresh())]);
    }

    /**
     * Gives Additional Funds back, newest extra payment first, each through its own payment (the money goes back the way it came, and
     * the card fee that came with it goes with it). Meant for a lease that ended without a purchase, after any repairs were applied.
     */
    public function refundBalance(Request $request, LeaseAgreement $leaseAgreement, PaymentRefunder $refunder)
    {
        $data = $request->validate([
            // Leave out to refund everything that is left.
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $admin = $request->user();

        $lease = LeaseAgreement::with(['application', 'contract'])->findOrFail($leaseAgreement->id);
        abort_if($reason = $this->refundBlocker($lease), 422, $reason);

        $available = AdditionalFundsLedger::available($lease);
        $wanted = isset($data['amount']) ? round((float) $data['amount'], 2) : $available;
        abort_if($wanted > $available, 422, sprintf('There is only $%s of Additional Funds that can still be refunded.', number_format($available, 2)));

        $payments = $lease->payments()
            ->where('type', Payment::TYPE_EXTRA_PAYMENT)
            ->where('status', Payment::STATUS_PAID)
            ->with('refunds')
            ->orderByDesc('id')
            ->get();

        $left = $wanted;
        $refunded = 0.0;
        $made = [];
        try {
            foreach ($payments as $payment) {
                if ($left < 0.01) {
                    break;
                }
                $total = $payment->totalCharged();
                if ($total <= 0 || $payment->refundableAmount() < 0.01) {
                    continue;
                }

                // How much of this payment's own money (not its card fee) can still be given back.
                $principalLeft = round($payment->refundableAmount() * ((float) $payment->amount / $total), 2);
                $principal = round(min($left, $principalLeft), 2);
                if ($principal < 0.01) {
                    continue;
                }
                // The refund carries the card fee share along with the principal.
                $amountToRefund = $principal >= $principalLeft ? $payment->refundableAmount() : round($principal * ($total / (float) $payment->amount), 2);

                $refund = $refunder->refund($payment, $amountToRefund, 'returned_equipment', $data['note'] ?? 'Additional Funds refunded after the equipment was returned.', $admin);
                $made[] = $refund->id;
                $refunded += $principal;
                $left = round($left - $principal, 2);
            }
        } catch (HttpException $e) {
            // Stopped part way: what already went back stays refunded (each is its own record), and the message says so.
            abort(422, $made === []
                ? $e->getMessage()
                : sprintf('$%s was refunded, then it stopped: %s', number_format($refunded, 2), $e->getMessage()));
        }

        abort_if($made === [], 422, 'None of the extra payments could be refunded. Check each payment on the lease.');

        AuditLog::record('funds.refunded', sprintf('$%s of Additional Funds was refunded to the customer.', number_format($refunded, 2)), [
            'lease' => $leaseAgreement, 'amount' => $refunded, 'details' => ['refund_ids' => $made],
        ]);

        return response()->json(['data' => $this->present($leaseAgreement->fresh())]);
    }

    /** Why funds cannot be applied to repairs, or null when they can. */
    private function repairsBlocker(LeaseAgreement $lease): ?string
    {
        if ($reason = $this->endedWithoutPurchaseBlocker($lease)) {
            return $reason;
        }
        if (! $lease->contract) {
            return 'There is no signed contract on this lease, so funds cannot be applied to repairs.';
        }
        if (! $lease->contract->appliesFundsToRepairsFirst()) {
            return 'This contract was signed before the repairs wording was added to Section 19, so funds cannot be applied to repairs without the customer\'s agreement.';
        }

        return null;
    }

    /** Why funds cannot be refunded, or null when they can. */
    private function refundBlocker(LeaseAgreement $lease): ?string
    {
        return $this->endedWithoutPurchaseBlocker($lease);
    }

    private function endedWithoutPurchaseBlocker(LeaseAgreement $lease): ?string
    {
        if (! $lease->hasEnded()) {
            return 'End the lease first. Additional Funds are applied to repairs or refunded once the equipment has come back.';
        }
        if ($lease->ended_reason === 'purchased' || $lease->ownership_status === LeaseAgreement::OWNERSHIP_OWNED) {
            return 'The customer bought the equipment, so there are no Additional Funds to apply or refund.';
        }
        if (AdditionalFundsLedger::available($lease) < 0.01) {
            return 'There are no Additional Funds left.';
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function present(LeaseAgreement $lease): array
    {
        $lease->load(['application', 'contract']);
        $entries = $lease->additionalFundsEntries()->with(['createdBy:id,name', 'payment:id,type,method,due_date'])->get()->sortByDesc('id')->values();

        return [
            'balance' => round((float) $lease->additional_funds, 2),
            'available' => AdditionalFundsLedger::available($lease),
            'epo_today' => round(LeaseEngine::epoToday($lease), 2),
            'entries' => $entries,
            'repairs_blocker' => $this->repairsBlocker($lease),
            'refund_blocker' => $this->refundBlocker($lease),
            'pending_refunds' => PaymentRefund::where('status', PaymentRefund::STATUS_PENDING)
                ->whereHas('payment', fn ($q) => $q->where('lease_agreement_id', $lease->id)->where('type', Payment::TYPE_EXTRA_PAYMENT))
                ->count(),
        ];
    }
}

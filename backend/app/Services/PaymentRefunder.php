<?php

namespace App\Services;

use App\Models\AdditionalFundsEntry;
use App\Models\Payment;
use App\Models\PaymentDispute;
use App\Models\PaymentPart;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Notifications\PaymentDisputedNotification;
use App\Notifications\PaymentRefundedNotification;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Stripe\Dispute;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\RateLimitException;
use Stripe\Refund;
use Stripe\StripeClient;

/**
 * Refunds (client, Joel, 2026-10-19: "we have to make sure that I'm able to give a refund"). Staff refund a paid payment in full or for
 * a typed amount, with a reason. A payment taken through Stripe is refunded through Stripe, to the card or bank account it came from; one
 * recorded by hand (cash, a check) was given back by hand, so the refund only records that.
 *
 * Every refund is a PaymentRefund row, created BEFORE Stripe is asked, with an idempotency key made from its id, so a double press or a
 * timeout can never refund twice, and counted against what is left to refund from the moment it exists. A refund counts for the business
 * (the payment's status, the Additional Funds, QuickBooks, the reports) only once it has succeeded: a card refund at once, a bank refund
 * when Stripe says so. A refund made in the Stripe dashboard and a chargeback come back through the webhook (the methods at the bottom).
 */
class PaymentRefunder
{
    /** Kept on a refund whose call to Stripe got no answer. It marks the row as one to look at, never to replace. */
    public const OUTCOME_UNKNOWN = 'No answer from the payment processor yet. It is not known whether this refund was made.';

    /** What Stripe calls a dispute that is over. */
    private const DISPUTE_CLOSED = ['won', 'lost', 'warning_closed', 'prevented'];

    private StripeClient $stripe;

    public function __construct(?StripeClient $stripe = null)
    {
        $this->stripe = $stripe ?? new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Refunds part or all of a paid payment. $amount is the money going back, card fee included, never more than what is left.
     */
    public function refund(Payment $payment, float $amount, string $reason, ?string $note, User $staff, ?PaymentPart $part = null): PaymentRefund
    {
        $cents = (int) round($amount * 100);

        $refund = DB::transaction(function () use ($payment, $cents, $reason, $note, $staff, $part) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_if($cents < 1, 422, 'Enter an amount to refund.');

            // A payment that was paid in parts is refunded one part at a time, each the way it was paid (its own card or bank account).
            $lockedPart = null;
            if ($locked->parts()->where('status', PaymentPart::STATUS_PAID)->exists()) {
                abort_unless($part, 422, 'This payment was paid in parts. Choose which part to refund.');
                $lockedPart = PaymentPart::whereKey($part->id)->where('payment_id', $locked->id)->lockForUpdate()->firstOrFail();
                abort_unless($lockedPart->status === PaymentPart::STATUS_PAID, 422, 'Only a part that was paid can be refunded.');
                // While the payment is still open, a part can only be taken back whole: it simply stops counting as paid.
                abort_if(
                    $locked->status !== Payment::STATUS_PAID && ($cents !== (int) round($lockedPart->refundableAmount() * 100) || $lockedPart->refundedTotal() > 0),
                    422,
                    'While the payment is still open, a part can only be refunded in full.',
                );
                abort_unless(in_array($locked->status, [Payment::STATUS_PAID, Payment::STATUS_PENDING, Payment::STATUS_FAILED], true), 422, 'This payment cannot be refunded.');
            } else {
                abort_if($part, 422, 'That part of the payment was not paid.');
                abort_unless($locked->status === Payment::STATUS_PAID, 422, 'Only a payment that was paid can be refunded.');
            }
            // The first monthly row "Mark Delivered" records next to a tracking fee and first month charge holds no money of its own.
            abort_if(app(PaymentReport::class)->isFirstMonthMirror($locked->load('leaseAgreement.payments')), 422, 'This row only mirrors the tracking fee and first month charge. Refund that charge instead.');

            $unanswered = $locked->refunds()->where('status', PaymentRefund::STATUS_PENDING)->whereNull('stripe_refund_id')->where('source', PaymentRefund::SOURCE_PORTAL)->exists();
            abort_if($unanswered, 422, 'An earlier refund of this payment has no answer from Stripe yet. Check Stripe, then cancel it here or wait for it.');

            $left = $lockedPart ? $lockedPart->refundableAmount() : $locked->refundableAmount();
            abort_if($cents > (int) round($left * 100), 422, 'You can refund at most $'.number_format($left, 2).($lockedPart ? ' of this part.' : ' of this payment.'));

            return $locked->refunds()->create([
                'payment_part_id' => $lockedPart?->id,
                'amount' => round($cents / 100, 2),
                'reason' => $reason,
                'note' => $note,
                'source' => ($lockedPart ?? $locked)->stripe_payment_intent_id ? PaymentRefund::SOURCE_PORTAL : PaymentRefund::SOURCE_MANUAL,
                'status' => PaymentRefund::STATUS_PENDING,
                'refunded_by' => $staff->id,
            ]);
        });

        $this->audit($refund, 'refund.started', sprintf('Refund of $%s started (%s).', number_format((float) $refund->amount, 2), $refund->reasonLabel()), ['actor' => $staff]);

        // Given back by hand: nothing to ask Stripe, the refund is recorded.
        if ($refund->source === PaymentRefund::SOURCE_MANUAL) {
            return $this->settle($refund);
        }

        try {
            $stripeRefund = $this->stripe->refunds->create([
                'payment_intent' => $part?->stripe_payment_intent_id ?? $payment->stripe_payment_intent_id,
                'amount' => $cents,
                'reason' => $reason === 'duplicate' ? 'duplicate' : 'requested_by_customer',
                'metadata' => ['refund_id' => (string) $refund->id, 'payment_id' => (string) $payment->id, 'portal_reason' => $reason],
            ], ['idempotency_key' => 'portal-refund-'.$refund->id]);
        } catch (ApiErrorException $e) {
            if ($this->outcomeIsUnknown($e)) {
                $refund->update(['failure_message' => self::OUTCOME_UNKNOWN]);
                Log::warning('A refund got no answer from Stripe and is left waiting.', ['refund_id' => $refund->id, 'error' => $e->getMessage()]);

                abort(503, 'Stripe did not answer, so it is not known whether the refund was made. Check Stripe before trying again. The refund stays on the payment as waiting, and you can cancel it here once you have checked.');
            }

            $refund->update(['status' => PaymentRefund::STATUS_FAILED, 'failure_message' => Str::limit($e->getMessage(), 500, '')]);
            $this->audit($refund, 'refund.failed', 'Stripe did not make the refund: '.Str::limit($e->getMessage(), 200, ''), ['actor' => $staff]);

            abort(422, 'Stripe did not make this refund: '.$e->getMessage());
        }

        $refund->update(['stripe_refund_id' => $stripeRefund->id]);

        if (in_array($stripeRefund->status, ['failed', 'canceled'], true)) {
            $refund->update(['status' => PaymentRefund::STATUS_FAILED, 'failure_message' => Str::limit((string) ($stripeRefund->failure_reason ?? 'Stripe could not make this refund.'), 500, '')]);

            $this->audit($refund, 'refund.failed', 'Stripe could not make the refund: '.($stripeRefund->failure_reason ?? $stripeRefund->status).'.', ['actor' => $staff]);

            abort(422, 'Stripe could not make this refund: '.($stripeRefund->failure_reason ?? $stripeRefund->status).'.');
        }

        // A card refund is done at once. A bank refund is on its way and counts when Stripe says it is done.
        return $stripeRefund->status === 'succeeded' ? $this->settle($refund->fresh()) : $refund->fresh();
    }

    /** Takes back a refund that got no answer from Stripe, once staff have checked in Stripe that none was made. */
    public function cancelUnanswered(PaymentRefund $refund, User $staff): PaymentRefund
    {
        abort_unless(
            $refund->status === PaymentRefund::STATUS_PENDING && ! $refund->stripe_refund_id && $refund->source === PaymentRefund::SOURCE_PORTAL,
            422,
            'Only a refund that Stripe never answered can be cancelled here.',
        );

        $refund->update([
            'status' => PaymentRefund::STATUS_FAILED,
            'failure_message' => "Cancelled by {$staff->name}: Stripe never confirmed it.",
        ]);
        $this->audit($refund, 'refund.cancelled', 'A refund Stripe never answered was cancelled after checking Stripe.', ['actor' => $staff]);

        return $refund->fresh();
    }

    /**
     * The refund has gone through: it now counts for the payment, the Additional Funds, QuickBooks and the reports. Safe to call twice.
     */
    public function settle(PaymentRefund $refund): PaymentRefund
    {
        $settled = DB::transaction(function () use ($refund) {
            $row = PaymentRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($row->status === PaymentRefund::STATUS_SUCCEEDED) {
                return null;
            }

            $payment = Payment::with(['leaseAgreement.customer', 'leaseAgreement.application'])->whereKey($row->payment_id)->lockForUpdate()->firstOrFail();
            $row->update(['status' => PaymentRefund::STATUS_SUCCEEDED, 'refunded_at' => now(), 'failure_message' => null]);

            $total = $payment->totalCharged();
            $lease = $payment->leaseAgreement;

            // Additional Funds (contract Section 19): what is refunded of an extra payment comes off the lease's funds, by the part of
            // the refund that was the payment itself (the card fee was never added to them).
            if ($payment->type === Payment::TYPE_EXTRA_PAYMENT && $total > 0) {
                AdditionalFundsLedger::apply($lease, AdditionalFundsEntry::KIND_REFUNDED, -1 * AdditionalFundsLedger::principalShare($payment, (float) $row->amount), [
                    'payment' => $payment, 'refund' => $row, 'user' => $row->refunded_by ? User::find($row->refunded_by) : null,
                    'description' => 'Refunded to the customer ('.$row->reasonLabel().').',
                ]);
            }

            // A part taken back whole from a payment that is still open stops counting as paid: what it covered is due again.
            if ($row->payment_part_id && $payment->status !== Payment::STATUS_PAID) {
                $part = PaymentPart::whereKey($row->payment_part_id)->lockForUpdate()->first();
                if ($part && $part->refundedTotal() >= $part->totalCharged() - 0.005) {
                    $part->update(['status' => PaymentPart::STATUS_REFUNDED]);
                }
            }

            $refundedInFull = (float) $payment->refunds()->where('status', PaymentRefund::STATUS_SUCCEEDED)->sum('amount') >= $total - 0.005;
            if ($refundedInFull && $payment->status === Payment::STATUS_PAID) {
                LeaseEngine::applyPaymentStatusChange($payment, Payment::STATUS_REFUNDED, $payment->method, $row->refunded_by, ['adjust_funds' => false, 'notify_customer' => false]);
            }

            if ($row->refunded_by && $lease->application) {
                $lease->application->dealerNotes()->create([
                    'author_user_id' => $row->refunded_by,
                    'text' => sprintf(
                        'Refunded $%s of the $%s %s payment due %s (%s).%s%s',
                        number_format((float) $row->amount, 2),
                        number_format($total, 2),
                        str_replace('_', ' ', $payment->type),
                        $payment->due_date?->toDateString() ?? 'unknown date',
                        $row->reasonLabel(),
                        $row->source === PaymentRefund::SOURCE_MANUAL ? ' The money was given back outside the portal.' : ' Refunded through Stripe.',
                        $row->note ? " Note: {$row->note}" : '',
                    ),
                ]);
            }

            return [$row->fresh(), $payment->fresh(['leaseAgreement.customer', 'leaseAgreement.application'])];
        });

        if ($settled === null) {
            return $refund->fresh();
        }

        [$row, $payment] = $settled;
        $this->audit($row, 'refund.completed', sprintf('$%s went back to the customer (%s).', number_format((float) $row->amount, 2), $row->reasonLabel()));
        // Outside the transaction, and caught: a mail problem must never undo a refund that was made.
        try {
            // A chargeback is the customer's own bank's doing: only staff hear of it. The customer's email for a refund is its receipt.
            if ($row->reason !== PaymentRefund::REASON_CHARGEBACK) {
                $payment->leaseAgreement->customer->notify(new PaymentRefundedNotification($payment, $row, ! PaymentReceipts::onRefund($row)));
            }
            Notification::send(PaymentStaff::recipients(), new PaymentRefundedNotification($payment, $row));
        } catch (\Throwable $e) {
            report($e);
        }

        return $row;
    }

    // ---- what Stripe tells us (the webhook) ----

    /**
     * A refund Stripe reports on (refund.created, refund.updated, refund.failed, charge.refund.updated). A refund made here is found by
     * its Stripe id, or, when the call that made it never got its answer, by the refund number the call put in its metadata. A refund
     * made in the Stripe dashboard is new to the portal and is recorded.
     */
    public function applyStripeRefund(Refund $stripeRefund): void
    {
        $row = PaymentRefund::where('stripe_refund_id', $stripeRefund->id)->first();

        if (! $row) {
            $metadata = $stripeRefund->metadata ? $stripeRefund->metadata->toArray() : [];
            $ours = ! empty($metadata['refund_id'])
                ? PaymentRefund::whereKey($metadata['refund_id'])->whereNull('stripe_refund_id')->where('source', PaymentRefund::SOURCE_PORTAL)->first()
                : null;
            if ($ours) {
                $ours->update(['stripe_refund_id' => $stripeRefund->id]);
                $row = $ours->fresh();
            }
        }

        if (! $row) {
            [$payment, $part] = $this->resolveIntent($stripeRefund->payment_intent ?? null);
            if (! $payment) {
                return;
            }

            try {
                $row = $payment->refunds()->create([
                    'payment_part_id' => $part?->id,
                    'amount' => round(((int) $stripeRefund->amount) / 100, 2),
                    'reason' => PaymentRefund::REASON_STRIPE,
                    'source' => PaymentRefund::SOURCE_STRIPE,
                    'status' => PaymentRefund::STATUS_PENDING,
                    'stripe_refund_id' => $stripeRefund->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The same refund arrived twice at once: the other delivery recorded it.
                $row = PaymentRefund::where('stripe_refund_id', $stripeRefund->id)->firstOrFail();
            }
        }

        if ($stripeRefund->status === 'succeeded') {
            $this->settle($row);
        } elseif (in_array($stripeRefund->status, ['failed', 'canceled'], true) && $row->status !== PaymentRefund::STATUS_SUCCEEDED) {
            $row->update(['status' => PaymentRefund::STATUS_FAILED, 'failure_message' => Str::limit((string) ($stripeRefund->failure_reason ?: "Stripe reports the refund {$stripeRefund->status}."), 500, '')]);
        }
    }

    /** charge.refunded names the charge, not the refund: ask Stripe for the refunds of that payment and apply each. */
    public function syncRefundsOf(?string $paymentIntentId): void
    {
        if (! $paymentIntentId || (! Payment::where('stripe_payment_intent_id', $paymentIntentId)->exists() && ! PaymentPart::where('stripe_payment_intent_id', $paymentIntentId)->exists())) {
            return;
        }

        foreach ($this->stripe->refunds->all(['payment_intent' => $paymentIntentId, 'limit' => 100])->data as $stripeRefund) {
            $this->applyStripeRefund($stripeRefund);
        }
    }

    /**
     * A chargeback Stripe reports on (charge.dispute.created, charge.dispute.closed). Staff are told when one opens, with the day the
     * evidence is due. One that is lost takes the money back, so it is recorded like a refund; one that is won changes nothing.
     */
    public function applyDispute(Dispute $stripeDispute): void
    {
        [$payment, $part] = $this->resolveIntent($stripeDispute->payment_intent ?? null);
        if (! $payment) {
            return;
        }

        $closed = in_array($stripeDispute->status, self::DISPUTE_CLOSED, true);
        $existing = PaymentDispute::where('stripe_dispute_id', $stripeDispute->id)->first();
        $dueBy = $stripeDispute->evidence_details->due_by ?? null;

        $dispute = $payment->disputes()->updateOrCreate(
            ['stripe_dispute_id' => $stripeDispute->id],
            [
                'amount' => round(((int) $stripeDispute->amount) / 100, 2),
                'reason' => $stripeDispute->reason ? Str::limit((string) $stripeDispute->reason, 64, '') : null,
                'status' => Str::limit((string) $stripeDispute->status, 32, ''),
                'evidence_due_by' => $dueBy ? Carbon::createFromTimestamp((int) $dueBy) : null,
                'opened_at' => $existing?->opened_at ?? Carbon::createFromTimestamp((int) ($stripeDispute->created ?? time())),
                'closed_at' => $closed ? ($existing?->closed_at ?? now()) : null,
            ],
        );

        if (! $existing) {
            AuditLog::record('chargeback.opened', sprintf('The customer disputed this payment with their bank: $%s, reason %s.', number_format((float) $dispute->amount, 2), $dispute->reason ?? 'not given'), [
                'payment' => $payment, 'amount' => $dispute->amount, 'system' => true, 'details' => ['dispute_id' => $dispute->stripe_dispute_id],
            ]);
        }
        if ($closed && ! $existing?->closed_at) {
            AuditLog::record('chargeback.closed', sprintf('The chargeback of $%s ended as %s.', number_format((float) $dispute->amount, 2), $dispute->status), [
                'payment' => $payment, 'amount' => $dispute->amount, 'system' => true, 'details' => ['dispute_id' => $dispute->stripe_dispute_id, 'status' => $dispute->status],
            ]);
        }

        // The account is frozen until the bank decides: the method is locked and AutoPay is paused. Repeating it for the same dispute is harmless,
        // so a retried delivery finishes a freeze that was cut short.
        if (! $closed) {
            PaymentMethodLocker::freezeForDispute($payment, $dispute, $part);
        }

        if (! $existing && ! $closed) {
            try {
                Notification::send(PaymentStaff::recipients(), new PaymentDisputedNotification($payment->loadMissing('leaseAgreement.customer', 'leaseAgreement.application'), $dispute));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Decided in our favour (or closed with no money moving): lift exactly what the chargeback froze.
        if ($closed && $stripeDispute->status !== 'lost' && ! $existing?->closed_at) {
            PaymentMethodLocker::liftDisputeFreeze($dispute);
        }

        if ($stripeDispute->status === 'lost') {
            $this->recordLostDispute($payment, $dispute, $part);
        }
    }

    /** A lost chargeback is money gone: a refund the customer's bank made, recorded once per dispute (its id marks the row). */
    private function recordLostDispute(Payment $payment, PaymentDispute $dispute, ?PaymentPart $part = null): void
    {
        if (PaymentRefund::where('stripe_refund_id', $dispute->stripe_dispute_id)->exists()) {
            return;
        }

        // Never more than what is left to give back (a refund may already have covered some of it).
        $amount = min((float) $dispute->amount, $part ? $part->fresh()->refundableAmount() : $payment->fresh()->refundableAmount());
        if ($amount <= 0) {
            return;
        }

        try {
            $row = $payment->refunds()->create([
                'payment_part_id' => $part?->id,
                'amount' => round($amount, 2),
                'reason' => PaymentRefund::REASON_CHARGEBACK,
                'source' => PaymentRefund::SOURCE_DISPUTE,
                'status' => PaymentRefund::STATUS_PENDING,
                'stripe_refund_id' => $dispute->stripe_dispute_id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $this->settle($row);
    }

    /** One line of the audit trail for a refund. */
    private function audit(PaymentRefund $refund, string $action, string $summary, array $context = []): void
    {
        $payment = $refund->payment()->with('leaseAgreement')->first();
        AuditLog::record($action, $summary, $context + [
            'payment' => $payment,
            'amount' => $refund->amount,
            'details' => ['refund_id' => $refund->id, 'source' => $refund->source, 'reason' => $refund->reason, 'note' => $refund->note],
        ]);
    }

    private function paymentFor(mixed $paymentIntent): ?Payment
    {
        return $this->resolveIntent($paymentIntent)[0];
    }

    /**
     * The payment a Stripe charge belongs to, and the part of it when the payment was paid in parts (each part has its own charge).
     *
     * @return array{0: ?Payment, 1: ?PaymentPart}
     */
    private function resolveIntent(mixed $paymentIntent): array
    {
        $id = is_object($paymentIntent) ? ($paymentIntent->id ?? null) : $paymentIntent;
        if (! $id) {
            return [null, null];
        }

        $part = PaymentPart::with('payment')->where('stripe_payment_intent_id', $id)->first();
        if ($part) {
            return [$part->payment, $part];
        }

        return [Payment::where('stripe_payment_intent_id', $id)->first(), null];
    }

    private function outcomeIsUnknown(ApiErrorException $e): bool
    {
        return $e instanceof ApiConnectionException
            || $e instanceof RateLimitException
            || $e instanceof IdempotencyException
            || ($e->getHttpStatus() ?? 0) >= 500;
    }
}

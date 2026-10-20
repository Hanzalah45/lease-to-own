<?php

namespace App\Services;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\PaymentPart;
use App\Models\RiskRedFlag;
use App\Models\User;
use App\Notifications\PaymentSafetyNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Locks and unlocks a saved bank account or card, and freezes a lease while a chargeback is open (client, Joel, 2026-10-19 checklist,
 * sections 2, 4 and 11). A locked method stays on file but is never charged (see LeaseAgreement::usableMethodId). Staff lock by hand; the
 * system locks after a hard decline, and for as long as a chargeback on the method is open.
 */
class PaymentMethodLocker
{
    /**
     * Declines that say the method itself is unusable, as opposed to empty this time. Stripe's own codes: a closed account or a stolen
     * card will fail every future charge too, and charging it again helps nobody (client checklist: "hard decline handling").
     */
    public const HARD_DECLINE_CODES = [
        'bank' => ['account_closed', 'no_account', 'invalid_account_number', 'bank_account_restricted', 'debit_not_authorized'],
        'card' => ['lost_card', 'stolen_card', 'fraudulent', 'pickup_card', 'restricted_card', 'security_violation'],
    ];

    public static function isHardDecline(string $methodType, ?string $code): bool
    {
        return $code !== null && in_array($code, self::HARD_DECLINE_CODES[$methodType] ?? [], true);
    }

    /** The word for a method type in a sentence. */
    public static function label(string $type): string
    {
        return $type === 'bank' ? 'bank account' : 'card';
    }

    /**
     * Locks a method. Returns false when it was already locked (the first reason is kept).
     */
    public static function lock(LeaseAgreement $lease, string $type, string $reason, ?User $by = null): bool
    {
        $newlyLocked = DB::transaction(function () use ($lease, $type, $reason, $by) {
            $locked = LeaseAgreement::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            if ($locked->isMethodLocked($type)) {
                return false;
            }
            $locked->update([
                "{$type}_locked_at" => now(),
                "{$type}_lock_reason" => Str::limit($reason, 200, ''),
                "{$type}_locked_by" => $by?->id,
            ]);

            return true;
        });

        if ($newlyLocked) {
            $lease->refresh();
            AuditLog::record('method.locked', sprintf('The saved %s was locked: %s', self::label($type), $reason), [
                'lease' => $lease, 'method' => $type === 'bank' ? 'ach' : 'card', 'actor' => $by, 'system' => $by === null,
            ]);
        }

        return $newlyLocked;
    }

    public static function unlock(LeaseAgreement $lease, string $type, ?User $by = null): bool
    {
        $wasLocked = DB::transaction(function () use ($lease, $type) {
            $locked = LeaseAgreement::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isMethodLocked($type)) {
                return false;
            }
            $locked->update(["{$type}_locked_at" => null, "{$type}_lock_reason" => null, "{$type}_locked_by" => null]);

            // A lock lifted by hand also ends the part of a chargeback freeze that held this method, so winning later cannot unlock it twice.
            if ($locked->chargeback_locked_method === $type) {
                $locked->update(['chargeback_locked_method' => null]);
            }

            return true;
        });

        if ($wasLocked) {
            $lease->refresh();
            AuditLog::record('method.unlocked', sprintf('The saved %s was unlocked.', self::label($type)), [
                'lease' => $lease, 'method' => $type === 'bank' ? 'ach' : 'card', 'actor' => $by, 'system' => $by === null,
            ]);
        }

        return $wasLocked;
    }

    /**
     * A charge was refused for a reason that will not go away (see HARD_DECLINE_CODES): lock that method so nothing tries it again, raise a
     * red flag, and tell staff. AutoPay then uses the other method, or stops until a person has looked.
     */
    public static function lockAfterHardDecline(Payment $payment, PaymentAttempt $attempt, ?string $code): void
    {
        $type = $attempt->method === 'bank' ? 'bank' : 'card';
        if (! self::isHardDecline($type, $code)) {
            return;
        }

        $lease = $payment->leaseAgreement()->with(['customer', 'application'])->first();
        if (! $lease || ! $lease->{$type === 'bank' ? 'stripe_bank_payment_method_id' : 'stripe_card_payment_method_id'}) {
            return;
        }

        $reason = sprintf('Hard decline (%s). Charging it again would fail.', $code);
        if (! self::lock($lease, $type, $reason)) {
            return;
        }

        RiskRedFlagger::flag($lease->customer_id, RiskRedFlag::TYPE_HARD_DECLINE, sprintf('The saved %s was declined for good (%s) on the $%s payment due %s and has been locked.', self::label($type), $code, number_format($payment->totalCharged(), 2), $payment->due_date?->toDateString() ?? 'unknown date'), $payment);
        Notification::send(PaymentStaff::recipients(), new PaymentSafetyNotification(
            $lease,
            'Payment method locked',
            sprintf('The saved %s was declined for good (%s), so it was locked and will not be charged again. Ask the customer for a new one, then unlock or replace it.', self::label($type), $code),
        ));
    }

    /**
     * A chargeback opened: freeze the lease until it is decided. The method the disputed payment was made with is locked, AutoPay is
     * paused, a red flag is raised and staff are told. Safe to call twice for the same dispute.
     */
    public static function freezeForDispute(Payment $payment, PaymentDispute $dispute, ?PaymentPart $part = null): void
    {
        $lease = $payment->leaseAgreement()->with(['customer', 'application'])->first();
        if (! $lease || $lease->chargeback_dispute_id === $dispute->stripe_dispute_id) {
            return;
        }

        $type = match ($part?->method ?? $payment->method) {
            'ach' => 'bank',
            'card' => 'card',
            default => null,
        };

        DB::transaction(function () use ($lease, $dispute) {
            $locked = LeaseAgreement::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            $alreadyPaused = $locked->autopay_paused_at !== null;

            $locked->update([
                'chargeback_dispute_id' => $dispute->stripe_dispute_id,
                'chargeback_paused_autopay_at' => $locked->autopay_enabled && ! $alreadyPaused ? now() : null,
                'autopay_paused_at' => $locked->autopay_paused_at ?? ($locked->autopay_enabled ? now() : null),
            ]);
            $lease->refresh();
        });

        $lockedByUs = $type ? self::lock($lease, $type, 'Chargeback open: the customer disputed a payment made with it.') : false;
        $lease->update(['chargeback_locked_method' => $lockedByUs ? $type : null]);

        AuditLog::record('chargeback.frozen', 'The lease was frozen while the chargeback is open: AutoPay paused'.($type ? ', and the saved '.self::label($type).' locked' : '').'.', [
            'lease' => $lease, 'payment' => $payment, 'system' => true, 'details' => ['dispute_id' => $dispute->stripe_dispute_id],
        ]);
        RiskRedFlagger::flag($lease->customer_id, RiskRedFlag::TYPE_CHARGEBACK, sprintf('The customer disputed the $%s payment due %s with their bank (%s).', number_format((float) $dispute->amount, 2), $payment->due_date?->toDateString() ?? 'unknown date', $dispute->reason ?? 'no reason given'), $payment);
        Notification::send(PaymentStaff::recipients(), new PaymentSafetyNotification(
            $lease,
            'Account frozen: chargeback',
            'The customer disputed a payment with their bank. AutoPay is paused'.($type ? ' and the saved '.self::label($type).' is locked' : '').' until the chargeback is decided.',
        ));
    }

    /**
     * The chargeback was decided in our favour (or closed without money moving): lift exactly what it froze. A lost one stays frozen
     * for a person to decide.
     */
    public static function liftDisputeFreeze(PaymentDispute $dispute): void
    {
        $lease = LeaseAgreement::with(['customer', 'application'])->where('chargeback_dispute_id', $dispute->stripe_dispute_id)->first();
        if (! $lease) {
            return;
        }

        $method = $lease->chargeback_locked_method;
        $resume = $lease->chargeback_paused_autopay_at !== null;

        $lease->update(['chargeback_dispute_id' => null, 'chargeback_locked_method' => null, 'chargeback_paused_autopay_at' => null]);
        if ($method) {
            self::unlock($lease, $method);
        }
        if ($resume) {
            $lease->update(['autopay_paused_at' => null]);
        }

        AuditLog::record('chargeback.unfrozen', 'The chargeback was decided in our favour: the freeze was lifted.', [
            'lease' => $lease, 'system' => true, 'details' => ['dispute_id' => $dispute->stripe_dispute_id, 'status' => $dispute->status],
        ]);
        Notification::send(PaymentStaff::recipients(), new PaymentSafetyNotification(
            $lease,
            'Chargeback decided in our favour',
            'The freeze was lifted'.($method ? ': the saved '.self::label($method).' is unlocked' : '').($resume ? ' and AutoPay is back on.' : '.'),
        ));
    }
}

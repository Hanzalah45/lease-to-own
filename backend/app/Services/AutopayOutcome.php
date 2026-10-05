<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Notifications\AutopayNeedsReviewNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Records what Stripe says happened to one automatic charge attempt. Makes no
 * Stripe calls, so the webhook can use it directly: AutopayCharger is the only
 * thing that ever creates a PaymentIntent, and this is the only thing that
 * ever moves a charged rental payment to paid or failed.
 *
 * Every entry point locks the payment row first, then re-reads the attempt, so
 * the charger's own response handling, a webhook and the daily reconcile can
 * all report the same attempt concurrently and only the first one has any effect.
 */
class AutopayOutcome
{
    /** The attempt's money arrived. */
    public static function succeeded(int $attemptId, ?string $intentId = null): void
    {
        DB::transaction(function () use ($attemptId, $intentId) {
            [$payment, $attempt] = self::lock($attemptId);
            if (! $attempt || $attempt->status === PaymentAttempt::STATUS_SUCCEEDED) {
                return;
            }

            $attempt->update([
                'status' => PaymentAttempt::STATUS_SUCCEEDED,
                'stripe_payment_intent_id' => $attempt->stripe_payment_intent_id ?? $intentId,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            if ($payment->status === Payment::STATUS_PAID) {
                // The payment was already settled (an earlier attempt, or staff
                // recording it by hand) and this attempt collected again: the
                // customer has paid twice and a person has to refund one.
                Log::critical('An AutoPay charge succeeded for a payment that was already paid.', [
                    'payment_id' => $payment->id,
                    'attempt_id' => $attempt->id,
                ]);
                Notification::send(PaymentStaff::recipients(), new AutopayNeedsReviewNotification(
                    $payment,
                    sprintf('an automatic charge of $%s succeeded after this payment was already paid. The customer may need a refund.', number_format($attempt->amount_cents / 100, 2)),
                ));

                return;
            }

            // The payment records the method and fee that actually collected,
            // which matters when the card fallback paid after a failed bank debit.
            $payment->update([
                'method' => $attempt->paymentMethodLabel(),
                'card_fee_amount' => $attempt->fee_cents / 100,
                'stripe_payment_intent_id' => $attempt->stripe_payment_intent_id,
            ]);

            LeaseEngine::applyPaymentStatusChange($payment->fresh(), Payment::STATUS_PAID, $attempt->paymentMethodLabel(), null);
        });
    }

    /** Stripe accepted the attempt and is still settling it (the normal ACH path). */
    public static function processing(int $attemptId, string $intentId): void
    {
        DB::transaction(function () use ($attemptId, $intentId) {
            [$payment, $attempt] = self::lock($attemptId);
            if (! $attempt || $attempt->status !== PaymentAttempt::STATUS_INITIATED) {
                return;
            }

            $attempt->update([
                'status' => PaymentAttempt::STATUS_PROCESSING,
                'stripe_payment_intent_id' => $attempt->stripe_payment_intent_id ?? $intentId,
            ]);
            // Marks the pending row as in flight for the missed-payment and
            // late-fee jobs, which skip a payment that has an intent attached.
            $payment->update(['stripe_payment_intent_id' => $attempt->stripe_payment_intent_id]);
        });
    }

    /**
     * The attempt did not collect. If the other method can still be tried the
     * payment stays pending and AutopayCharger's next run does that; otherwise
     * the payment is marked failed (risk flag and notifications included).
     */
    public static function failed(int $attemptId, ?string $intentId, ?string $code, ?string $message): void
    {
        DB::transaction(function () use ($attemptId, $intentId, $code, $message) {
            [$payment, $attempt] = self::lock($attemptId);
            if (! $attempt) {
                return;
            }
            if ($attempt->status === PaymentAttempt::STATUS_SUCCEEDED) {
                Log::warning('Ignored a failure report for an AutoPay attempt that already succeeded.', ['attempt_id' => $attempt->id]);

                return;
            }
            if ($attempt->status === PaymentAttempt::STATUS_FAILED) {
                return;
            }

            $attempt->update([
                'status' => PaymentAttempt::STATUS_FAILED,
                'stripe_payment_intent_id' => $attempt->stripe_payment_intent_id ?? $intentId,
                'failure_code' => $code ? mb_substr($code, 0, 255) : null,
                'failure_message' => $message ? mb_substr($message, 0, 500) : null,
            ]);

            self::settleFailure($payment, $attempt);
        });
    }

    /**
     * A pending payment whose latest attempt failed with nothing left to try
     * (an interrupted run left it that way): finish marking it failed.
     */
    public static function exhausted(int $attemptId): void
    {
        DB::transaction(function () use ($attemptId) {
            [$payment, $attempt] = self::lock($attemptId);
            if ($attempt?->status === PaymentAttempt::STATUS_FAILED) {
                self::settleFailure($payment, $attempt);
            }
        });
    }

    /** After an attempt fails: keep the payment pending if the other method can still be tried, otherwise fail it. */
    private static function settleFailure(Payment $payment, PaymentAttempt $attempt): void
    {
        if ($payment->status !== Payment::STATUS_PENDING) {
            return;
        }

        $payment->update([
            'method' => $attempt->paymentMethodLabel(),
            'card_fee_amount' => $attempt->fee_cents / 100,
            'stripe_payment_intent_id' => $attempt->stripe_payment_intent_id,
        ]);

        if (self::fallbackMethodFor($payment, $attempt)) {
            return;
        }

        LeaseEngine::applyPaymentStatusChange($payment->fresh(), Payment::STATUS_FAILED, $attempt->paymentMethodLabel(), null);
    }

    /**
     * The other saved method, when the failed attempt was a round's first and
     * the lease still allows charging; null once the round is used up.
     *
     * @return array{type: string, id: string}|null
     */
    public static function fallbackMethodFor(Payment $payment, PaymentAttempt $failed): ?array
    {
        $lease = $payment->leaseAgreement()->first();
        if (AutopayCharger::leaseBlocker($lease)) {
            return null;
        }
        if ($payment->attempts()->where('round', $failed->round)->count() >= 2) {
            return null;
        }

        return $lease->paymentMethodFor($failed->method === 'bank' ? 'card' : 'bank');
    }

    /** @return array{0: Payment, 1: ?PaymentAttempt} the payment row locked, and the attempt read fresh under that lock */
    private static function lock(int $attemptId): array
    {
        $paymentId = PaymentAttempt::whereKey($attemptId)->value('payment_id');
        if (! $paymentId) {
            return [new Payment, null];
        }

        $payment = Payment::whereKey($paymentId)->lockForUpdate()->first();

        return [$payment, PaymentAttempt::find($attemptId)];
    }
}

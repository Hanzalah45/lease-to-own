<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\StripeWebhookEvent;
use App\Services\AutopayOutcome;
use App\Services\LeaseEngine;
use App\Services\PaymentRefunder;
use App\Services\PaymentSplitter;
use App\Services\StripePaymentMethodService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Webhook;

/**
 * Receives Stripe's server-to-server webhook calls — not reachable through
 * auth:sanctum, since Stripe is not a logged-in user; the Stripe-Signature
 * header (verified below against STRIPE_WEBHOOK_SECRET) is what proves a
 * request actually came from Stripe instead of anyone who finds the URL.
 *
 * Only records outcomes; nothing here creates a PaymentIntent or talks to
 * Stripe. Two kinds of intent end up here: a customer-initiated deposit or
 * pickup charge (the Payment row that references the intent is marked paid or
 * failed), and an AutoPay monthly charge (the matching payment_attempts row is
 * resolved by AutopayOutcome, which also decides whether the payment fails or
 * the next run should try the other method). AutopayCharger alone creates
 * the latter.
 */
class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $secret = config('services.stripe.webhook_secret');
        if (! $secret) {
            Log::warning('Stripe webhook received before STRIPE_WEBHOOK_SECRET was configured — ignoring.');

            return response()->json(['message' => 'Webhook not configured.'], 503);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                $secret,
            );
        } catch (\UnexpectedValueException|SignatureVerificationException $e) {
            Log::warning('Rejected a Stripe webhook with an invalid payload or signature.', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid payload or signature.'], 400);
        }

        // Stripe redelivers events on a slow response, a transient 5xx, or a
        // manual dashboard retry — recording the event id FIRST (the unique
        // index makes the insert the atomic claim, so two simultaneous
        // deliveries can't both pass an exists() check) turns a retried
        // delivery into a no-op instead of double-applying a status change.
        try {
            $record = StripeWebhookEvent::create([
                'stripe_event_id' => $event->id,
                'type' => $event->type,
                'processed_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Already processed.']);
        }

        try {
            $this->applyEvent($event);
        } catch (\Throwable $e) {
            // Release the claim so Stripe's retry of this event is processed
            // instead of being swallowed as "already processed".
            $record->delete();

            throw $e;
        }

        return response()->json(['message' => 'Processed.']);
    }

    private function applyEvent(Event $event): void
    {
        match ($event->type) {
            'payment_intent.succeeded' => $this->handleIntent($event->data->object, Payment::STATUS_PAID),
            'payment_intent.payment_failed' => $this->handleIntent($event->data->object, Payment::STATUS_FAILED),
            // Refunds and chargebacks: made here, made in the Stripe dashboard, or decided by the customer's bank.
            'refund.created', 'refund.updated', 'refund.failed', 'charge.refund.updated' => app(PaymentRefunder::class)->applyStripeRefund($event->data->object),
            'charge.refunded' => app(PaymentRefunder::class)->syncRefundsOf($this->intentIdOf($event->data->object)),
            'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed' => app(PaymentRefunder::class)->applyDispute($event->data->object),
            // A bank account waiting for its two small deposits was confirmed, or could not be.
            'setup_intent.succeeded' => app(StripePaymentMethodService::class)->applySetupIntentEvent($event->data->object, true),
            'setup_intent.setup_failed', 'setup_intent.canceled' => app(StripePaymentMethodService::class)->applySetupIntentEvent($event->data->object, false),
            default => null,
        };
    }

    /** The payment intent a charge belongs to (Stripe sends it as an id). */
    private function intentIdOf(object $charge): ?string
    {
        $intent = $charge->payment_intent ?? null;

        return is_object($intent) ? ($intent->id ?? null) : $intent;
    }

    private function handleIntent(PaymentIntent $intent, string $status): void
    {
        // A charge for one piece of a payment that is paid in pieces settles that piece.
        if (app(PaymentSplitter::class)->applyIntent($intent, $status === Payment::STATUS_PAID ? 'paid' : 'failed')) {
            return;
        }

        $attempt = $this->attemptFor($intent);
        if (! $attempt) {
            $this->markFromIntent($intent, $status);

            return;
        }

        if ($status === Payment::STATUS_PAID) {
            AutopayOutcome::succeeded($attempt->id, $intent->id);

            return;
        }

        $error = $intent->last_payment_error;
        AutopayOutcome::failed(
            $attempt->id,
            $intent->id,
            $error ? ($error['decline_code'] ?? $error['code'] ?? null) : null,
            $error ? ($error['message'] ?? null) : null,
        );
    }

    /**
     * The AutoPay attempt this intent belongs to: by its intent id, or, when
     * the event beat the charger's own bookkeeping (the attempt has no intent
     * id stored yet), by the payment and attempt number the charger put in the
     * intent's metadata.
     */
    private function attemptFor(PaymentIntent $intent): ?PaymentAttempt
    {
        $attempt = PaymentAttempt::where('stripe_payment_intent_id', $intent->id)->first();
        if ($attempt) {
            return $attempt;
        }

        $metadata = $intent->metadata ? $intent->metadata->toArray() : [];
        if (! in_array($metadata['type'] ?? null, [Payment::TYPE_RENTAL, Payment::TYPE_EXTRA_PAYMENT], true) || empty($metadata['payment_id']) || empty($metadata['attempt_no'])) {
            return null;
        }

        return PaymentAttempt::where('payment_id', $metadata['payment_id'])
            ->where('attempt_no', $metadata['attempt_no'])
            ->whereNull('stripe_payment_intent_id')
            ->first();
    }

    /** The deposit or pickup charge whose call never got an answer and which this intent belongs to, now tied to the intent. */
    private function unansweredChargeFor(PaymentIntent $intent): ?Payment
    {
        $metadata = $intent->metadata ? $intent->metadata->toArray() : [];
        if (! in_array($metadata['type'] ?? null, [Payment::TYPE_DEPOSIT, Payment::TYPE_PICKUP_BALANCE], true) || empty($metadata['payment_id'])) {
            return null;
        }

        $payment = Payment::whereKey($metadata['payment_id'])
            ->where('type', $metadata['type'])
            ->where('lease_agreement_id', $metadata['lease_id'] ?? 0)
            ->whereNull('stripe_payment_intent_id')
            ->lockForUpdate()
            ->first();
        $payment?->update(['stripe_payment_intent_id' => $intent->id]);

        return $payment;
    }

    private function markFromIntent(PaymentIntent $intent, string $status): void
    {
        DB::transaction(function () use ($intent, $status) {
            $payment = Payment::where('stripe_payment_intent_id', $intent->id)->lockForUpdate()->first();
            if (! $payment) {
                // The charge call can time out after Stripe took the charge, leaving the row
                // without its intent id (StripeDepositPaymentService keeps such a row open as
                // "outcome unknown"). The intent's own metadata still names the row.
                $payment = $this->unansweredChargeFor($intent);
            }
            if (! $payment) {
                // No scheduled payment row references this intent — nothing to update.
                return;
            }

            // Events can arrive out of order (a late "failed" for an intent
            // that already succeeded); a paid payment never goes back to failed.
            if ($payment->status === Payment::STATUS_PAID && $status === Payment::STATUS_FAILED) {
                Log::warning('Ignored a Stripe payment_failed event for an already-paid payment.', ['payment_id' => $payment->id]);

                return;
            }

            if ($status === Payment::STATUS_FAILED) {
                $message = $intent->last_payment_error['message'] ?? null;
                if ($message) {
                    $payment->update(['failure_message' => Str::limit($message, 500, '')]);
                }
            }

            LeaseEngine::applyPaymentStatusChange($payment, $status, $payment->method, null);
        });
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\StripeWebhookEvent;
use App\Services\AutopayOutcome;
use App\Services\LeaseEngine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            default => null,
        };
    }

    private function handleIntent(PaymentIntent $intent, string $status): void
    {
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
        if (($metadata['type'] ?? null) !== Payment::TYPE_RENTAL || empty($metadata['payment_id']) || empty($metadata['attempt_no'])) {
            return null;
        }

        return PaymentAttempt::where('payment_id', $metadata['payment_id'])
            ->where('attempt_no', $metadata['attempt_no'])
            ->whereNull('stripe_payment_intent_id')
            ->first();
    }

    private function markFromIntent(PaymentIntent $intent, string $status): void
    {
        DB::transaction(function () use ($intent, $status) {
            $payment = Payment::where('stripe_payment_intent_id', $intent->id)->lockForUpdate()->first();
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

            LeaseEngine::applyPaymentStatusChange($payment, $status, $payment->method, null);
        });
    }
}

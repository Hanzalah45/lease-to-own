<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\StripeWebhookEvent;
use App\Services\LeaseEngine;
use Illuminate\Http\Request;
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
 * Only handles the part of a charge's outcome that holds true no matter how
 * the PaymentIntent was created — ACH vs card, AutoPay auto-charging vs a
 * manually approved charge, all end the same way: the scheduled Payment row
 * that intent was for gets marked paid or failed. Nothing here creates a
 * PaymentIntent — that's the actual charging flow, still blocked on the two
 * open architecture questions (payment method type, AutoPay auto vs manual).
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
        // manual dashboard retry — this makes a retried delivery a no-op
        // instead of double-applying a payment status change.
        if (StripeWebhookEvent::where('stripe_event_id', $event->id)->exists()) {
            return response()->json(['message' => 'Already processed.']);
        }

        $this->applyEvent($event);

        StripeWebhookEvent::create([
            'stripe_event_id' => $event->id,
            'type' => $event->type,
            'processed_at' => now(),
        ]);

        return response()->json(['message' => 'Processed.']);
    }

    private function applyEvent(Event $event): void
    {
        match ($event->type) {
            'payment_intent.succeeded' => $this->markFromIntent($event->data->object, Payment::STATUS_PAID),
            'payment_intent.payment_failed' => $this->markFromIntent($event->data->object, Payment::STATUS_FAILED),
            default => null,
        };
    }

    private function markFromIntent(PaymentIntent $intent, string $status): void
    {
        $payment = Payment::where('stripe_payment_intent_id', $intent->id)->first();
        if (! $payment) {
            // No scheduled payment row references this intent — nothing to update.
            return;
        }

        LeaseEngine::applyPaymentStatusChange($payment, $status, $payment->method, null);
    }
}

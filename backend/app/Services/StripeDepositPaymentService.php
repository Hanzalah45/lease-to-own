<?php

namespace App\Services;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Real Stripe charging for the deposit (+ tracking fee + first month) —
 * client, Joel, 2026-10-01. Builds on StripePaymentMethodService (no
 * duplicated Stripe-customer logic) rather than replacing it; the existing
 * manual "Mark Deposit Received" admin action stays the fallback this
 * doesn't touch — see LeaseEngine::applyPaymentStatusChange()'s
 * deposit_received sync for how the two converge.
 */
class StripeDepositPaymentService
{
    private StripeClient $stripe;

    public function __construct(
        private readonly StripePaymentMethodService $paymentMethods,
        ?StripeClient $stripe = null,
    ) {
        $this->stripe = $stripe ?? new StripeClient(config('services.stripe.secret'));
    }

    /**
     * @return array{payment: Payment, requires_action: bool, client_secret: ?string}
     */
    public function charge(LeaseAgreement $lease): array
    {
        $method = $lease->autopayChargeablePaymentMethod();
        abort_unless($method, 422, 'Add a bank account or card before paying your deposit.');

        $existing = $lease->payments()->where('type', Payment::TYPE_DEPOSIT)->latest()->first();
        abort_if($existing?->status === Payment::STATUS_PAID, 422, 'The deposit has already been paid.');
        abort_if($existing?->status === Payment::STATUS_PENDING, 422, 'A deposit payment is already processing.');

        $amount = $lease->totalDueAtSigning();
        $stripeCustomerId = $this->paymentMethods->ensureStripeCustomer($lease->customer);

        $payment = Payment::create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_DEPOSIT,
            'amount' => $amount,
            'due_date' => now()->toDateString(),
            'status' => Payment::STATUS_PENDING,
            'method' => $method['type'] === 'bank' ? 'ach' : 'card',
        ]);

        try {
            $intent = $this->stripe->paymentIntents->create([
                'amount' => (int) round($amount * 100),
                'currency' => 'usd',
                'customer' => $stripeCustomerId,
                'payment_method' => $method['id'],
                'payment_method_types' => [$method['type'] === 'bank' ? 'us_bank_account' : 'card'],
                'confirm' => true,
                'description' => "Deposit, tracking fee & first month \u{2014} Lease #{$lease->id}",
            ]);
        } catch (ApiErrorException $e) {
            // A synchronous card decline arrives as a thrown exception, not a
            // normal response — Stripe still attaches the resulting
            // PaymentIntent (status requires_payment_method) to the error, so
            // capture its id the same way a successful call would.
            $declinedIntent = $e->getError()->payment_intent ?? null;
            $payment->update(['stripe_payment_intent_id' => $declinedIntent->id ?? null]);
            LeaseEngine::applyPaymentStatusChange($payment->fresh(), Payment::STATUS_FAILED, $payment->method, null);

            abort(422, $e->getMessage());
        }

        $payment->update(['stripe_payment_intent_id' => $intent->id]);

        return match ($intent->status) {
            'succeeded' => [
                'payment' => LeaseEngine::applyPaymentStatusChange($payment->fresh(), Payment::STATUS_PAID, $payment->method, null),
                'requires_action' => false,
                'client_secret' => null,
            ],
            'requires_payment_method' => [
                'payment' => LeaseEngine::applyPaymentStatusChange($payment->fresh(), Payment::STATUS_FAILED, $payment->method, null),
                'requires_action' => false,
                'client_secret' => null,
            ],
            'requires_action' => [
                'payment' => $payment->fresh(),
                'requires_action' => true,
                'client_secret' => $intent->client_secret,
            ],
            // 'processing' — the normal ACH path, resolved later by the webhook.
            default => [
                'payment' => $payment->fresh(),
                'requires_action' => false,
                'client_secret' => null,
            ],
        };
    }
}

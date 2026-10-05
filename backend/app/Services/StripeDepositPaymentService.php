<?php

namespace App\Services;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Real Stripe charging for the deposit and, separately, the tracking-fee +
 * first-month "pickup balance" (client, Joel, 2026-10-01 / split into two
 * independent charges 2026-10-02 so "pay deposit only" can defer the latter).
 * Builds on StripePaymentMethodService (no duplicated Stripe-customer logic)
 * rather than replacing it; the existing manual "Mark Deposit Received"
 * admin action stays the fallback this doesn't touch — see
 * LeaseEngine::applyPaymentStatusChange()'s deposit_received/
 * pickup_balance_received syncs for how the two converge.
 *
 * Deliberately never combines the two into one PaymentIntent for "pay in
 * full" — StripeWebhookController::markFromIntent() resolves a Payment row by
 * a single stripe_payment_intent_id, so two rows sharing one intent would
 * strand one of them at "pending" forever on an async (ACH) resolution. "Pay
 * in full" is the frontend sequencing chargeDepositOnly() then
 * chargePickupBalance(), each its own independent PaymentIntent.
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
    public function chargeDepositOnly(LeaseAgreement $lease, ?string $methodType = null, ?int $expectedTotalCents = null): array
    {
        return $this->chargeType($lease, Payment::TYPE_DEPOSIT, $lease->depositAmountDue(), "Security deposit \u{2014} Lease #{$lease->id}", $methodType, $expectedTotalCents);
    }

    /**
     * @return array{payment: Payment, requires_action: bool, client_secret: ?string}
     */
    public function chargePickupBalance(LeaseAgreement $lease, ?string $methodType = null, ?int $expectedTotalCents = null): array
    {
        return $this->chargeType($lease, Payment::TYPE_PICKUP_BALANCE, $lease->pickupBalanceAmountDue(), "Tracking fee & first month \u{2014} Lease #{$lease->id}", $methodType, $expectedTotalCents);
    }

    /**
     * @return array{payment: Payment, requires_action: bool, client_secret: ?string}
     */
    private function chargeType(LeaseAgreement $lease, string $type, float $amount, string $description, ?string $methodType, ?int $expectedTotalCents): array
    {
        // The customer picks bank or card per payment (dual pricing, client
        // 2026-10-05); with no explicit choice the AutoPay primary is used.
        $method = $lease->paymentMethodFor($methodType);
        abort_unless($method, 422, $methodType ? 'That payment method is not on file. Add it first.' : 'Add a bank account or card before paying.');

        // Priced per charge in whole cents. The expected total is what the
        // customer saw on screen: if it no longer matches (the price or the
        // method changed underneath them) refuse rather than charge a number
        // they did not agree to.
        $bankCents = CardPricing::toCents($amount);
        $feeCents = CardPricing::feeCentsFor($bankCents, $method['type']);
        $totalCents = $bankCents + $feeCents;
        abort_if(
            $expectedTotalCents !== null && $expectedTotalCents !== $totalCents,
            409,
            'The amount changed. Please review the new total and try again.',
        );

        $label = $type === Payment::TYPE_DEPOSIT ? 'The deposit' : 'The pickup balance';
        $assertNotAlreadyCharged = function () use ($lease, $type, $label) {
            $existing = $lease->payments()->where('type', $type)->latest()->first();
            abort_if($existing?->status === Payment::STATUS_PAID, 422, "{$label} has already been paid.");
            abort_if($existing?->status === Payment::STATUS_PENDING, 422, "{$label} payment is already processing.");
        };

        // Cheap fast-fail before any Stripe call, re-checked under a lock below.
        $assertNotAlreadyCharged();
        $stripeCustomerId = $this->paymentMethods->ensureStripeCustomer($lease->customer);

        // The check-then-insert runs under a lock on the lease row, so a
        // double-click (two requests in flight) can't both pass the "already
        // pending" check and create two charges.
        $payment = DB::transaction(function () use ($lease, $type, $amount, $feeCents, $method, $assertNotAlreadyCharged) {
            LeaseAgreement::whereKey($lease->id)->lockForUpdate()->first();
            $assertNotAlreadyCharged();

            return Payment::create([
                'lease_agreement_id' => $lease->id,
                'type' => $type,
                'amount' => $amount,
                'card_fee_amount' => $feeCents / 100,
                'due_date' => BillingClock::todayDate(),
                'status' => Payment::STATUS_PENDING,
                'method' => $method['type'] === 'bank' ? 'ach' : 'card',
            ]);
        });

        try {
            $intent = $this->stripe->paymentIntents->create([
                'amount' => $totalCents,
                'currency' => 'usd',
                'customer' => $stripeCustomerId,
                'payment_method' => $method['id'],
                'payment_method_types' => [$method['type'] === 'bank' ? 'us_bank_account' : 'card'],
                'confirm' => true,
                'description' => $feeCents > 0 ? $description.' (includes card processing fee)' : $description,
                'metadata' => ['payment_id' => $payment->id, 'lease_id' => $lease->id, 'type' => $type],
            ], ['idempotency_key' => "customer-charge-payment-{$payment->id}"]);
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

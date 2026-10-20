<?php

namespace App\Services;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentPart;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\RateLimitException;
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
    /** Kept on a charge whose call to Stripe got no answer; it marks the row as one to repeat, never to replace. */
    public const OUTCOME_UNKNOWN = 'No answer from the payment processor yet. It is not known whether this charge went through.';

    private StripeClient $stripe;

    public function __construct(
        private readonly StripePaymentMethodService $paymentMethods,
        ?StripeClient $stripe = null,
    ) {
        $this->stripe = $stripe ?? new StripeClient(config('services.stripe.secret'));
    }

    /**
     * $staffUserId is set when an admin charges the customer's saved method
     * after the customer agreed (client, Joel, 2026-10-12): the charge is then
     * made off-session (the customer is not at a screen to approve it) and the
     * payment records who ran it.
     *
     * @return array{payment: Payment, requires_action: bool, client_secret: ?string}
     */
    public function chargeDepositOnly(LeaseAgreement $lease, ?string $methodType = null, ?int $expectedTotalCents = null, ?int $staffUserId = null): array
    {
        return $this->chargeType($lease, Payment::TYPE_DEPOSIT, $lease->depositAmountDue(), "Security deposit \u{2014} Lease #{$lease->id}", $methodType, $expectedTotalCents, $staffUserId);
    }

    /**
     * $staffUserId works as for chargeDepositOnly(): staff charge the saved method after the customer agreed (client,
     * Joel, 2026-10-16: a customer took the mower before paying the tracking fee and first month).
     *
     * @return array{payment: Payment, requires_action: bool, client_secret: ?string}
     */
    public function chargePickupBalance(LeaseAgreement $lease, ?string $methodType = null, ?int $expectedTotalCents = null, ?int $staffUserId = null): array
    {
        return $this->chargeType($lease, Payment::TYPE_PICKUP_BALANCE, $lease->pickupBalanceAmountDue(), "Tracking fee & first month \u{2014} Lease #{$lease->id}", $methodType, $expectedTotalCents, $staffUserId);
    }

    /**
     * @return array{payment: Payment, requires_action: bool, client_secret: ?string}
     */
    private function chargeType(LeaseAgreement $lease, string $type, float $amount, string $description, ?string $methodType, ?int $expectedTotalCents, ?int $staffUserId = null): array
    {
        // A deposit or pickup balance that was already paid in part (cash first, the rest by card): what is left is charged as one more
        // piece of that same payment, never as a new row for the full amount.
        $split = $lease->payments()
            ->where('type', $type)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_FAILED])
            ->whereHas('parts', fn ($q) => $q->whereIn('status', ['paid', 'pending']))
            ->latest('id')
            ->first();
        if ($split) {
            $chosen = $lease->paymentMethodFor($methodType);
            abort_unless($chosen, 422, $lease->methodRefusal($methodType));
            $result = app(PaymentSplitter::class)->charge(
                $split,
                $chosen['type'],
                $split->chargeableAmount(),
                $expectedTotalCents,
                $staffUserId ? User::find($staffUserId) : null,
                $staffUserId ? PaymentPart::BY_STAFF : PaymentPart::BY_CUSTOMER,
            );

            return ['payment' => $split->fresh(), 'requires_action' => $result['status'] === 'requires_action', 'client_secret' => $result['client_secret']];
        }
        // The customer picks bank or card per payment (dual pricing, client
        // 2026-10-05); with no explicit choice the AutoPay primary is used.
        $method = $lease->paymentMethodFor($methodType);
        abort_unless($method, 422, $lease->methodRefusal($methodType));

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
        // Returns the earlier attempt this one must pick up again, if any: a charge
        // whose call got no answer from Stripe (see OUTCOME_UNKNOWN). It is never
        // charged as a new row, since the first call may well have gone through.
        $assertNotAlreadyCharged = function () use ($lease, $type, $label): ?Payment {
            $existing = $lease->payments()->where('type', $type)->latest('id')->first();
            abort_if($existing?->status === Payment::STATUS_PAID, 422, "{$label} has already been paid.");
            if ($existing?->status === Payment::STATUS_PENDING) {
                $unanswered = ! $existing->stripe_payment_intent_id && $existing->failure_message === self::OUTCOME_UNKNOWN;
                abort_unless($unanswered, 422, "{$label} payment is already processing.");

                return $existing;
            }

            return null;
        };

        // Cheap fast-fail before any Stripe call, re-checked under a lock below.
        $assertNotAlreadyCharged();
        $stripeCustomerId = $this->paymentMethods->ensureStripeCustomer($lease->customer);

        // The check-then-insert runs under a lock on the lease row, so a
        // double-click (two requests in flight) can't both pass the "already
        // pending" check and create two charges.
        $methodName = $method['type'] === 'bank' ? 'ach' : 'card';
        $payment = DB::transaction(function () use ($lease, $type, $amount, $bankCents, $feeCents, $methodName, $assertNotAlreadyCharged, $staffUserId) {
            LeaseAgreement::whereKey($lease->id)->lockForUpdate()->first();
            $unanswered = $assertNotAlreadyCharged();

            if ($unanswered) {
                // Same charge as before (same amount, fee and method), or Stripe could reject the
                // repeated idempotency key and the earlier outcome would stay unsettled.
                abort_unless(
                    CardPricing::toCents((float) $unanswered->amount) === $bankCents
                        && CardPricing::toCents((float) $unanswered->card_fee_amount) === $feeCents
                        && $unanswered->method === $methodName,
                    422,
                    'An earlier charge for this never got an answer from the payment processor. Check Stripe for it before charging again.',
                );

                return $unanswered;
            }

            return Payment::create([
                'lease_agreement_id' => $lease->id,
                'type' => $type,
                'amount' => $amount,
                'card_fee_amount' => $feeCents / 100,
                'due_date' => BillingClock::todayDate(),
                'status' => Payment::STATUS_PENDING,
                'method' => $methodName,
                'recorded_by' => $staffUserId,
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
                // A staff-run charge has no customer at a screen to approve a
                // bank prompt, so it is declared off-session (the saved method
                // was set up for that); a card that needs the customer present
                // is declined cleanly instead of hanging.
                ...($staffUserId ? ['off_session' => true] : []),
                'description' => $feeCents > 0 ? $description.' (includes card processing fee)' : $description,
                'metadata' => ['payment_id' => $payment->id, 'lease_id' => $lease->id, 'type' => $type]
                    + ($staffUserId ? ['charged_by_staff_id' => $staffUserId] : []),
            ], ['idempotency_key' => "customer-charge-payment-{$payment->id}"]);
        } catch (ApiErrorException $e) {
            // No answer, or a server error, says nothing about whether Stripe took the
            // charge: it must not be recorded as declined (the next click would then
            // charge a second time). The row stays open and the next attempt repeats
            // the very same call (same idempotency key), which Stripe answers with the
            // first result instead of charging again; a late webhook also settles it.
            if ($this->outcomeIsUnknown($e)) {
                $payment->update(['failure_message' => self::OUTCOME_UNKNOWN]);
                Log::warning('A deposit or pickup charge got no answer from Stripe and is left open.', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);

                abort(503, 'The payment processor did not answer, so it is not known whether the charge went through. Wait a minute and try again: the same charge is checked and is never made twice.');
            }

            // A synchronous card decline arrives as a thrown exception, not a
            // normal response — Stripe still attaches the resulting
            // PaymentIntent (status requires_payment_method) to the error, so
            // capture its id the same way a successful call would.
            $declinedIntent = $e->getError()->payment_intent ?? null;
            // Only a bank's own decline is worded for a customer to read. Any other Stripe error
            // (a bad request, a key or account problem) is internal wording, kept in the log.
            if ($e instanceof CardException) {
                $reason = $e->getMessage();
            } else {
                Log::error('Stripe refused a deposit or pickup charge.', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
                $reason = 'The payment could not be processed. Please try again, or contact us if it keeps failing.';
            }
            $payment->update([
                'stripe_payment_intent_id' => $declinedIntent->id ?? null,
                'failure_message' => Str::limit($reason, 500, ''),
            ]);
            LeaseEngine::applyPaymentStatusChange($payment->fresh(), Payment::STATUS_FAILED, $payment->method, $staffUserId);

            abort(422, $reason);
        }

        $payment->update(['stripe_payment_intent_id' => $intent->id, 'failure_message' => null]);

        return match ($intent->status) {
            'succeeded' => [
                'payment' => LeaseEngine::applyPaymentStatusChange($payment->fresh(), Payment::STATUS_PAID, $payment->method, $staffUserId),
                'requires_action' => false,
                'client_secret' => null,
            ],
            'requires_payment_method' => [
                'payment' => $this->markFailed($payment, $intent->last_payment_error->message ?? null, $staffUserId),
                'requires_action' => false,
                'client_secret' => null,
            ],
            'requires_action' => $staffUserId
                ? $this->abandonNeedsCustomer($payment, $intent->id, $staffUserId)
                : [
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

    /**
     * A staff charge has no customer at a screen to approve a prompt, so a charge that
     * needs one is cancelled and recorded as failed: left pending it would block every
     * later charge, the customer's own included, and no webhook would ever clear it.
     *
     * @return array{payment: Payment, requires_action: bool, client_secret: ?string}
     */
    private function abandonNeedsCustomer(Payment $payment, string $intentId, int $staffUserId): array
    {
        try {
            $this->stripe->paymentIntents->cancel($intentId);
        } catch (ApiErrorException $e) {
            Log::warning('Could not cancel a staff deposit charge that needs the customer to approve it.', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
        }

        return [
            'payment' => $this->markFailed($payment, 'The customer has to approve this payment themselves, so it cannot be charged for them. Ask them to pay from their own account.', $staffUserId),
            'requires_action' => false,
            'client_secret' => null,
        ];
    }

    /** True when Stripe may or may not have taken the charge: a timeout, a rate limit, a server error, or a repeated key it could not match. */
    private function outcomeIsUnknown(ApiErrorException $e): bool
    {
        return $e instanceof ApiConnectionException
            || $e instanceof RateLimitException
            || $e instanceof IdempotencyException
            || ($e->getHttpStatus() ?? 0) >= 500;
    }

    /** Marks a charge failed and keeps the processor's reason next to it, so staff can see why. */
    private function markFailed(Payment $payment, ?string $message, ?int $staffUserId): Payment
    {
        if ($message) {
            $payment->update(['failure_message' => Str::limit($message, 500, '')]);
        }

        return LeaseEngine::applyPaymentStatusChange($payment->fresh(), Payment::STATUS_FAILED, $payment->method, $staffUserId);
    }
}

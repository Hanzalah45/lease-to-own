<?php

namespace App\Services;

use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Pay now": a customer pays a due monthly payment themselves, with a bank
 * account or card they chose (client, Joel, 2026-10-15). Real case the same day:
 * a customer with AutoPay switched off had a payment due and no way to pay it
 * from the portal, and most customers are expected to pay from their own phone.
 *
 * It rides on the same payment_attempts records as AutoPay (a charge made here
 * is told apart by its idempotency key, see PaymentAttempt::startedByCustomer),
 * so the webhook, the reconcile of slow bank debits and the staff views all keep
 * working. Differences from AutoPay: the customer is present (a card can ask for
 * extra confirmation on screen), the customer picks the method and sees the exact
 * price first, and a failure never falls back to the other method by itself.
 *
 * Same safety as the other charge paths: the attempt is recorded under a row lock
 * before Stripe is called, with an idempotency key, so a double press, a timeout
 * or a crash can never charge twice; an unknown outcome is repeated, not replaced.
 */
class CustomerPaymentCharger
{
    private StripeClient $stripe;

    public function __construct(
        private readonly StripePaymentMethodService $paymentMethods,
        ?StripeClient $stripe = null,
    ) {
        $this->stripe = $stripe ?? new StripeClient(config('services.stripe.secret'));
    }

    /** Why the customer cannot pay this payment themselves right now, or null when they can. */
    public static function blocker(Payment $payment, LeaseAgreement $lease): ?string
    {
        $isExtra = $payment->type === Payment::TYPE_EXTRA_PAYMENT;
        if ($payment->type !== Payment::TYPE_RENTAL && ! $isExtra) {
            return 'Only a monthly payment can be paid here.';
        }
        if ($payment->status === Payment::STATUS_PAID) {
            return 'This payment has already been paid.';
        }
        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_FAILED], true)) {
            return 'This payment cannot be paid here.';
        }
        if (! $isExtra && $payment->due_date->toDateString() > BillingClock::todayDate()) {
            return 'This payment is not due yet.';
        }
        if ($lease->application?->status !== Application::STATUS_FINISHED) {
            return 'The equipment has not been picked up yet.';
        }
        if ($lease->customer?->status === 'suspended') {
            return 'This account is suspended. Please contact us.';
        }

        $latest = $payment->attempts()->reorder()->orderByDesc('id')->first();
        if (! $latest) {
            // An intent attached by some other path means a charge is already in flight.
            return $payment->stripe_payment_intent_id && $payment->status === Payment::STATUS_PENDING
                ? 'A payment for this month is already being processed.'
                : null;
        }

        return match ($latest->status) {
            // The customer's own unconfirmed press is simply repeated; an automatic one is left alone.
            PaymentAttempt::STATUS_INITIATED => $latest->startedByCustomer()
                ? null
                : 'This payment is being collected automatically right now. Please check again in a few minutes.',
            // A bank debit really is processing for days. A card left waiting for confirmation (the customer
            // closed the extra confirmation step) is cleaned up by the next press.
            PaymentAttempt::STATUS_PROCESSING => $latest->startedByCustomer() && $latest->method === 'card'
                ? null
                : 'A bank payment '.($isExtra ? 'is' : 'for this month is').' still processing. It usually clears in 1 to 4 business days.',
            default => null,
        };
    }

    /** The smallest extra payment (or the whole early purchase price when that is less). */
    public const EXTRA_PAYMENT_MIN = 5.00;

    /**
     * Why the customer cannot make an extra payment right now, or null when they can. Contract Section 19 applies money
     * paid on top of the monthly payment first to anything accrued (a missed monthly payment, a late fee) and only then
     * to the early purchase price, so while something is due the customer pays that first.
     */
    public static function extraPaymentBlocker(LeaseAgreement $lease, bool $forCustomer = true): ?string
    {
        if ($lease->hasEnded()) {
            return 'This lease has ended.';
        }
        if ($lease->application?->status !== Application::STATUS_FINISHED) {
            return 'The equipment has not been picked up yet.';
        }
        if ($lease->ownership_status === LeaseAgreement::OWNERSHIP_OWNED || LeaseEngine::epoToday($lease) <= 0) {
            return 'There is nothing left to pay toward the early purchase price.';
        }
        if ($forCustomer && $lease->customer?->status === 'suspended') {
            return 'This account is suspended. Please contact us.';
        }

        $today = BillingClock::todayDate();
        $owed = $lease->payments()
            ->whereIn('type', [Payment::TYPE_RENTAL, Payment::TYPE_LATE_FEE])
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_FAILED])
            ->whereDate('due_date', '<=', $today)
            // A charge already on its way (a bank debit takes days) is not money still owed.
            ->where(fn ($q) => $q->where('status', Payment::STATUS_FAILED)->orWhereNull('stripe_payment_intent_id'))
            ->exists();
        if ($owed) {
            return 'Please pay the amount that is due first. Extra payments go toward the early purchase price once nothing is due.';
        }

        // An extra payment still being charged (a bank debit takes days, a press that has not been answered yet).
        $inFlight = $lease->payments()
            ->where('type', Payment::TYPE_EXTRA_PAYMENT)
            ->where('status', Payment::STATUS_PENDING)
            ->where(fn ($q) => $q->whereNotNull('stripe_payment_intent_id')->orWhere('created_at', '>=', now()->subMinutes(2)))
            ->exists();
        if ($inFlight) {
            return 'An extra payment is still being processed. Please wait until it clears before making another one.';
        }

        return null;
    }

    /**
     * What the portal shows for an extra payment: whether it can be made, why not, the range, the saved methods and the
     * card fee, so the exact price can be shown before anything is charged.
     *
     * @return array{available: bool, blocker: ?string, min: float, max: float, available_methods: list<string>, card_fee_percent: float}
     */
    public static function extraPaymentOptions(LeaseAgreement $lease): array
    {
        $max = max(0.0, LeaseEngine::epoToday($lease));
        $methods = array_values(array_filter([
            $lease->stripe_bank_payment_method_id ? 'bank' : null,
            $lease->stripe_card_payment_method_id ? 'card' : null,
        ]));
        $blocker = self::extraPaymentBlocker($lease) ?? ($methods === [] ? 'Save a bank account or card first.' : null);

        return [
            'available' => $blocker === null,
            'blocker' => $blocker,
            'min' => round(min(self::EXTRA_PAYMENT_MIN, $max), 2),
            'max' => round($max, 2),
            'available_methods' => $methods,
            'card_fee_percent' => CardPricing::ratePercent(),
        ];
    }

    /**
     * Starts an extra payment of the given amount (bank price; the card adds its fee on top) with the chosen method.
     * Money lands in the lease's Additional Funds once it is paid (LeaseEngine::applyPaymentStatusChange).
     *
     * @return array{payment: Payment, status: string, message: ?string, client_secret: ?string}
     */
    public function payExtra(LeaseAgreement $lease, float $amount, ?string $methodType, ?int $expectedTotalCents): array
    {
        $payment = DB::transaction(function () use ($lease, $amount) {
            // One extra payment at a time, decided under a lock on the lease so a double press cannot start two.
            LeaseAgreement::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            $lease->refresh()->load(['application', 'customer']);
            abort_if($reason = self::extraPaymentBlocker($lease), 422, $reason);

            $options = self::extraPaymentOptions($lease);
            $amount = round($amount, 2);
            abort_if($amount < $options['min'] || $amount > $options['max'], 422, sprintf(
                'Enter an amount between $%s and $%s.',
                number_format($options['min'], 2),
                number_format($options['max'], 2),
            ));

            // A press that never reached the processor (nothing attached) and has gone stale is cleared away, not kept.
            $lease->payments()
                ->where('type', Payment::TYPE_EXTRA_PAYMENT)
                ->where('status', Payment::STATUS_PENDING)
                ->whereNull('stripe_payment_intent_id')
                ->where('created_at', '<', now()->subMinutes(2))
                ->whereDoesntHave('attempts')
                ->delete();

            return Payment::create([
                'lease_agreement_id' => $lease->id,
                'type' => Payment::TYPE_EXTRA_PAYMENT,
                'amount' => $amount,
                'due_date' => BillingClock::todayDate(),
                'status' => Payment::STATUS_PENDING,
            ]);
        });

        try {
            return $this->pay($payment, $methodType, $expectedTotalCents);
        } catch (HttpException $e) {
            // Refused before any charge was recorded (the price moved, the method is not on file): the row has done its job
            // and must not keep the next press waiting. One that has an attempt (an unknown outcome) is kept and repeated.
            if (! $payment->attempts()->exists()) {
                $payment->delete();
            }

            throw $e;
        }
    }

    /**
     * What the portal shows next to a payment: whether it can be paid now, why not, which saved methods can be
     * used and both prices. Null for a payment that is not due or is not a monthly payment.
     *
     * @return array{payable: bool, blocker: ?string, available_methods: list<string>, prices: array{bank: float, card: float, card_fee: float}}|null
     */
    public static function options(Payment $payment, LeaseAgreement $lease): ?array
    {
        if ($payment->type !== Payment::TYPE_RENTAL
            || ! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_FAILED], true)
            || $payment->due_date->toDateString() > BillingClock::todayDate()) {
            return null;
        }

        $blocker = self::blocker($payment, $lease);

        return [
            'payable' => $blocker === null,
            'blocker' => $blocker,
            'available_methods' => array_values(array_filter([
                $lease->stripe_bank_payment_method_id ? 'bank' : null,
                $lease->stripe_card_payment_method_id ? 'card' : null,
            ])),
            'prices' => CardPricing::both((float) $payment->amount),
        ];
    }

    /**
     * Charges the payment with the chosen method.
     *
     * @return array{payment: Payment, status: string, message: ?string, client_secret: ?string} status is paid, processing, failed or requires_action
     */
    public function pay(Payment $payment, ?string $methodType, ?int $expectedTotalCents): array
    {
        $this->settleAbandonedCardAttempt($payment);

        $attempt = DB::transaction(function () use ($payment, $methodType, $expectedTotalCents) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $lease = LeaseAgreement::with(['application', 'customer'])->findOrFail($locked->lease_agreement_id);
            abort_if($reason = self::blocker($locked, $lease), 422, $reason);

            $method = $lease->paymentMethodFor($methodType);
            abort_unless($method, 422, $methodType ? 'That payment method is not on file. Add it first.' : 'Add a bank account or card before paying.');

            // Priced per charge in whole cents, and checked against the total the customer saw on screen.
            $bankCents = CardPricing::toCents((float) $locked->amount);
            $feeCents = CardPricing::feeCentsFor($bankCents, $method['type']);
            $totalCents = $bankCents + $feeCents;
            abort_if($expectedTotalCents !== null && $expectedTotalCents !== $totalCents, 409, 'The amount changed. Please review the new total and try again.');

            $latest = $locked->attempts()->reorder()->orderByDesc('id')->first();
            if ($latest && $latest->status === PaymentAttempt::STATUS_INITIATED && $latest->startedByCustomer()) {
                // A press that never got its answer is repeated as it was (same key), or not at all.
                abort_unless(
                    $latest->method === $method['type'] && $latest->amount_cents === $totalCents && $latest->stripe_payment_method_id === $method['id'],
                    422,
                    'An earlier payment for this month has not been confirmed yet. Please try the same method again, or contact us.',
                );

                return $latest;
            }

            $attemptNo = ($latest?->attempt_no ?? 0) + 1;
            $attempt = PaymentAttempt::create([
                'payment_id' => $locked->id,
                'attempt_no' => $attemptNo,
                'round' => ($latest?->round ?? 0) + 1,
                'method' => $method['type'],
                'amount_cents' => $totalCents,
                'fee_cents' => $feeCents,
                'stripe_payment_method_id' => $method['id'],
                'idempotency_key' => PaymentAttempt::CUSTOMER_KEY_PREFIX."{$locked->id}-a{$attemptNo}-{$method['type']}",
                'status' => PaymentAttempt::STATUS_INITIATED,
            ]);

            // Pending again while it is being collected (a failed payment is retried this way too), set directly:
            // nobody should be told a payment "changed" to pending.
            $locked->update([
                'status' => Payment::STATUS_PENDING,
                'method' => $attempt->paymentMethodLabel(),
                'card_fee_amount' => $feeCents / 100,
            ]);

            return $attempt;
        });

        return $this->send($attempt);
    }

    /** Sends one claimed (or repeated) attempt to Stripe and records the answer. */
    private function send(PaymentAttempt $attempt): array
    {
        $attempt->loadMissing('payment.leaseAgreement.customer');
        $payment = $attempt->payment;
        $lease = $payment->leaseAgreement;

        try {
            $intent = $this->stripe->paymentIntents->create([
                'amount' => $attempt->amount_cents,
                'currency' => 'usd',
                'customer' => $this->paymentMethods->ensureStripeCustomer($lease->customer),
                'payment_method' => $attempt->stripe_payment_method_id,
                'payment_method_types' => [$attempt->method === 'bank' ? 'us_bank_account' : 'card'],
                'confirm' => true,
                // No off_session: the customer is here, so a card may ask for extra confirmation on screen.
                'description' => sprintf(
                    '%s, Lease #%d%s',
                    $payment->type === Payment::TYPE_EXTRA_PAYMENT
                        ? 'Extra payment toward the early purchase price'
                        : 'Monthly payment due '.$payment->due_date->toDateString(),
                    $lease->id,
                    $attempt->fee_cents > 0 ? ' (includes card processing fee)' : '',
                ),
                'metadata' => [
                    'payment_id' => $payment->id,
                    'lease_id' => $lease->id,
                    'attempt_no' => $attempt->attempt_no,
                    'type' => $payment->type === Payment::TYPE_EXTRA_PAYMENT ? Payment::TYPE_EXTRA_PAYMENT : Payment::TYPE_RENTAL,
                ],
            ], ['idempotency_key' => $attempt->idempotency_key]);
        } catch (CardException $e) {
            // A bank's decline arrives as an exception with the unconfirmed intent attached; nothing was charged.
            $declined = $e->getError()->payment_intent ?? null;
            AutopayOutcome::failed($attempt->id, $declined->id ?? null, $e->getError()->decline_code ?? $e->getStripeCode() ?? 'card_declined', $e->getMessage());

            return $this->result($attempt, 'failed', $e->getMessage());
        } catch (InvalidRequestException $e) {
            // Stripe rejected the request itself (an unusable saved method): nothing was charged. Its wording is
            // internal, so the customer gets a plain sentence and the detail goes to the log.
            Log::error('Stripe refused a customer payment request.', ['attempt_id' => $attempt->id, 'error' => $e->getMessage()]);
            AutopayOutcome::failed($attempt->id, null, $e->getStripeCode() ?? 'invalid_request', 'The saved payment method could not be used.');

            return $this->result($attempt, 'failed', 'The saved payment method could not be used. Please add a new one and try again.');
        } catch (\Throwable $e) {
            // A timeout, a server error or a rate limit says nothing about whether Stripe took the charge, so the
            // attempt stays as it is and the next press repeats the very same request (same key).
            Log::warning('A customer payment got no answer from Stripe and is left open.', ['attempt_id' => $attempt->id, 'error' => $e->getMessage()]);

            abort(503, 'The payment processor did not answer, so it is not known whether the payment went through. Wait a minute and try again: the same payment is checked and is never made twice.');
        }

        switch ($intent->status) {
            case 'succeeded':
                AutopayOutcome::succeeded($attempt->id, $intent->id);

                return $this->result($attempt, 'paid');

            case 'processing':
                AutopayOutcome::processing($attempt->id, $intent->id);

                return $this->result($attempt, 'processing');

            case 'requires_action':
                // The card wants an extra confirmation: the screen shows it with the client secret. The attempt is
                // kept as "processing" with its intent id so the webhook finishes it, or the next press cleans it up.
                AutopayOutcome::processing($attempt->id, $intent->id);

                return $this->result($attempt, 'requires_action', null, $intent->client_secret);

            case 'requires_payment_method':
            case 'canceled':
                $message = $intent->last_payment_error->message ?? 'The payment did not go through.';
                AutopayOutcome::failed($attempt->id, $intent->id, $intent->last_payment_error->code ?? $intent->status, $message);

                return $this->result($attempt, 'failed', $message);

            default:
                $this->cancelQuietly($intent->id);
                AutopayOutcome::failed($attempt->id, $intent->id, $intent->status, 'The payment needs further action.');

                return $this->result($attempt, 'failed', 'The payment could not be completed. Please try again.');
        }
    }

    /**
     * A card payment left waiting for its extra confirmation (the customer closed that step) would block the next
     * press for good, because AutoPay may be off and nothing else cleans it up. Ask Stripe what became of it and
     * record that.
     */
    private function settleAbandonedCardAttempt(Payment $payment): void
    {
        $latest = $payment->attempts()->reorder()->orderByDesc('id')->first();
        if (! $latest || ! $latest->startedByCustomer() || $latest->status !== PaymentAttempt::STATUS_PROCESSING
            || $latest->method !== 'card' || ! $latest->stripe_payment_intent_id) {
            return;
        }

        try {
            $intent = $this->stripe->paymentIntents->retrieve($latest->stripe_payment_intent_id);
        } catch (\Throwable $e) {
            return;
        }

        switch ($intent->status) {
            case 'succeeded':
                AutopayOutcome::succeeded($latest->id, $intent->id);
                break;
            case 'requires_action':
            case 'requires_payment_method':
            case 'requires_confirmation':
                $this->cancelQuietly($intent->id);
                AutopayOutcome::failed($latest->id, $intent->id, $intent->status, 'The payment was not confirmed.');
                break;
            case 'canceled':
                AutopayOutcome::failed($latest->id, $intent->id, 'canceled', 'The payment was cancelled.');
                break;
        }
    }

    private function cancelQuietly(string $intentId): void
    {
        try {
            $this->stripe->paymentIntents->cancel($intentId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array{payment: Payment, status: string, message: ?string, client_secret: ?string} */
    private function result(PaymentAttempt $attempt, string $status, ?string $message = null, ?string $clientSecret = null): array
    {
        return [
            'payment' => $attempt->payment->fresh(),
            'status' => $status,
            'message' => $message,
            'client_secret' => $clientSecret,
        ];
    }
}

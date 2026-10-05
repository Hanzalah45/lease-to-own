<?php

namespace App\Services;

use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Notifications\AutopayNeedsReviewNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;

/**
 * Automatic monthly charging (client, Joel, 2026-10-05): collects a scheduled
 * rental payment off-session with the customer's primary saved method and, if
 * that fails, once more with the other one (at that method's price: the card
 * pays the card price, see CardPricing).
 *
 * Built as a resumable state machine so no failure can charge a customer
 * twice or lose track of a charge:
 *
 *   1. CLAIM, under a row lock: decide the next step and insert a
 *      payment_attempts row holding the exact amount, method and idempotency
 *      key, then commit.
 *   2. CALL STRIPE outside any transaction, with that idempotency key.
 *   3. RECORD the answer through AutopayOutcome, which locks again.
 *
 * A crash or timeout between 2 and 3 leaves the attempt `initiated`; the next
 * run replays the identical request and Stripe returns the original result
 * instead of charging again. An unknown outcome never falls back to the other
 * method: only a definite failure does.
 *
 * Only this class creates PaymentIntents for rental payments; the webhook just
 * reports their outcomes (AutopayOutcome).
 */
class AutopayCharger
{
    /** Stripe forgets idempotency keys after 24 hours; past this a replay could create a second charge, so a person must look instead. */
    public const REPLAY_WINDOW_HOURS = 20;

    private StripeClient $stripe;

    public function __construct(
        private readonly StripePaymentMethodService $paymentMethods,
        ?StripeClient $stripe = null,
    ) {
        $this->stripe = $stripe ?? new StripeClient(config('services.stripe.secret'));
    }

    /** Why this lease cannot be charged automatically right now, or null when it can. */
    public static function leaseBlocker(LeaseAgreement $lease): ?string
    {
        if (! $lease->autopay_enabled) {
            return 'AutoPay is not enabled on this lease.';
        }
        if ($lease->autopay_paused_at) {
            return 'AutoPay is paused on this lease.';
        }
        if ($lease->application?->status !== Application::STATUS_FINISHED) {
            return 'The equipment has not been picked up yet.';
        }
        if ($lease->customer?->status === 'suspended') {
            return 'The customer account is suspended.';
        }
        if (! $lease->autopayChargeablePaymentMethod()) {
            return 'No bank account or card is on file.';
        }

        return null;
    }

    /**
     * What should happen next for this payment, without doing it. Pure apart
     * from reading the database, so `payments:charge-autopay --dry-run` shows
     * exactly the decision a real run would make.
     *
     * @param  array{retry?: bool, gate?: bool}  $options  `retry`: an admin is retrying a failed payment; `gate`: hold first attempts until the configured morning hour (default true)
     * @return array{action: string, reason?: string, attempt?: PaymentAttempt, kind?: string, round?: int, attempt_no?: int, method?: string, payment_method_id?: string, amount_cents?: int, fee_cents?: int}
     */
    public function plan(Payment $payment, array $options = []): array
    {
        $retry = $options['retry'] ?? false;
        $gate = $options['gate'] ?? true;

        if ($payment->type !== Payment::TYPE_RENTAL) {
            return $this->skip('Only rental payments are charged automatically.');
        }
        $expectedStatus = $retry ? Payment::STATUS_FAILED : Payment::STATUS_PENDING;
        if ($payment->status !== $expectedStatus) {
            return $this->skip($retry ? 'Only a failed payment can be retried.' : "The payment is {$payment->status}.");
        }

        $lease = $payment->leaseAgreement()->with(['application', 'customer'])->first();
        if ($reason = self::leaseBlocker($lease)) {
            return $this->skip($reason);
        }

        $attempts = $payment->attempts()->get();
        $latest = $attempts->last();

        if ($retry) {
            return $this->chargeSpec($payment, 'retry', ($latest?->round ?? 0) + 1, ($latest?->attempt_no ?? 0) + 1, $lease->autopayChargeablePaymentMethod());
        }

        if (! $latest) {
            // An intent attached by some other path (not this charger) means a
            // charge is already in flight; never start a second one beside it.
            if ($payment->stripe_payment_intent_id) {
                return $this->skip('A charge is already in flight for this payment.');
            }
            if ($payment->due_date->toDateString() > BillingClock::todayDate()) {
                return $this->skip('Not due yet.');
            }
            if ($gate && BillingClock::now()->hour < config('billing.autopay_first_attempt_hour')) {
                return $this->skip('Waiting for the morning charge time.');
            }

            return $this->chargeSpec($payment, 'first', 1, 1, $lease->autopayChargeablePaymentMethod());
        }

        switch ($latest->status) {
            case PaymentAttempt::STATUS_INITIATED:
                if ($latest->created_at->lt(now()->subHours(self::REPLAY_WINDOW_HOURS))) {
                    return ['action' => 'stale', 'attempt' => $latest, 'reason' => 'The charge was never confirmed and is too old to replay safely.'];
                }

                return ['action' => 'resume', 'attempt' => $latest];

            case PaymentAttempt::STATUS_PROCESSING:
                $cutoff = now()->subDays((int) config('billing.autopay_reconcile_after_days'));

                return $latest->updated_at->lt($cutoff)
                    ? ['action' => 'reconcile', 'attempt' => $latest]
                    : $this->skip('Waiting for the bank to settle the debit.');

            case PaymentAttempt::STATUS_FAILED:
                $fallback = AutopayOutcome::fallbackMethodFor($payment, $latest);
                if ($fallback) {
                    return $this->chargeSpec($payment, 'fallback', $latest->round, $latest->attempt_no + 1, $fallback);
                }

                // Nothing left to try. This only shows up if the payment was
                // left pending by an interrupted run, so finish the job.
                return ['action' => 'exhausted', 'attempt' => $latest];
        }

        return $this->skip('The payment was already collected.');
    }

    /**
     * Carries out the next step for one payment: claims it, calls Stripe, and
     * keeps going while a definite failure leaves a fallback method to try.
     *
     * @return string charged|processing|failed|unknown|reconciled|stale|skipped
     */
    public function process(Payment $payment, array $options = []): string
    {
        // What the last real attempt came to, so a pass that finds nothing
        // further to do (the payment just failed) reports that, not "skipped".
        $last = null;

        for ($step = 0; $step < 3; $step++) {
            $next = $this->claimNext($payment->id, $options);
            // Only the first pass of an admin retry is a retry: if it fails
            // the following pass is the ordinary fallback.
            $options['retry'] = false;

            switch ($next['action']) {
                case 'charge':
                case 'resume':
                    $last = $this->execute($next['attempt']);
                    if ($last !== 'failed') {
                        return $last;
                    }
                    break;

                case 'reconcile':
                    return $this->reconcile($next['attempt']);

                case 'exhausted':
                    AutopayOutcome::exhausted($next['attempt']->id);

                    return 'failed';

                case 'stale':
                    $this->flagForReview($payment, 'an automatic charge was started but Stripe never confirmed it, and it is too old to retry safely. Check the payment in Stripe before charging again.');

                    return 'stale';

                default:
                    return $last ?? 'skipped';
            }
        }

        return 'failed';
    }

    /**
     * Admin retry of a failed payment (starts a new round with the primary
     * method again). Throws a 422 when the payment cannot be retried.
     */
    public function retry(Payment $payment): string
    {
        $plan = $this->plan($payment, ['retry' => true, 'gate' => false]);
        abort_unless($plan['action'] === 'charge', 422, $plan['reason'] ?? 'This payment cannot be retried.');

        return $this->process($payment, ['retry' => true, 'gate' => false]);
    }

    /** Decides the next step under a lock and, when it is a new charge, records the attempt before anything is sent to Stripe. */
    private function claimNext(int $paymentId, array $options): array
    {
        return DB::transaction(function () use ($paymentId, $options) {
            $payment = Payment::whereKey($paymentId)->lockForUpdate()->first();
            $plan = $this->plan($payment, $options);

            if ($plan['action'] !== 'charge') {
                return $plan;
            }

            $attempt = PaymentAttempt::create([
                'payment_id' => $payment->id,
                'attempt_no' => $plan['attempt_no'],
                'round' => $plan['round'],
                'method' => $plan['method'],
                'amount_cents' => $plan['amount_cents'],
                'fee_cents' => $plan['fee_cents'],
                'stripe_payment_method_id' => $plan['payment_method_id'],
                'idempotency_key' => "autopay-{$payment->id}-a{$plan['attempt_no']}-{$plan['method']}",
                'status' => PaymentAttempt::STATUS_INITIATED,
            ]);

            // A retried payment is pending again while it is being collected.
            // Set directly, not through applyPaymentStatusChange: nobody should
            // be notified that a payment "changed" to pending.
            $payment->update([
                'status' => Payment::STATUS_PENDING,
                'method' => $attempt->paymentMethodLabel(),
                'card_fee_amount' => $plan['fee_cents'] / 100,
            ]);

            return ['action' => 'charge', 'attempt' => $attempt];
        });
    }

    /** Sends one claimed (or replayed) attempt to Stripe and records the answer. */
    private function execute(PaymentAttempt $attempt): string
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
                'off_session' => true,
                'description' => sprintf(
                    'Monthly payment due %s, Lease #%d%s',
                    $payment->due_date->toDateString(),
                    $lease->id,
                    $attempt->fee_cents > 0 ? ' (includes card processing fee)' : '',
                ),
                'metadata' => [
                    'payment_id' => $payment->id,
                    'lease_id' => $lease->id,
                    'attempt_no' => $attempt->attempt_no,
                    'type' => Payment::TYPE_RENTAL,
                ],
            ], ['idempotency_key' => $attempt->idempotency_key]);
        } catch (CardException $e) {
            // A decline arrives as an exception with the (unconfirmed)
            // PaymentIntent attached; Stripe says nothing was charged.
            $declined = $e->getError()->payment_intent ?? null;
            AutopayOutcome::failed($attempt->id, $declined->id ?? null, $e->getError()->decline_code ?? $e->getStripeCode() ?? 'card_declined', $e->getMessage());

            return 'failed';
        } catch (RateLimitException|ApiConnectionException $e) {
            return $this->unknown($attempt, $e);
        } catch (InvalidRequestException $e) {
            // Stripe rejected the request itself (a detached or unusable
            // payment method, say): nothing was charged and retrying the same
            // method cannot help, so it counts as that method failing.
            AutopayOutcome::failed($attempt->id, null, $e->getStripeCode() ?? 'invalid_request', $e->getMessage());

            return 'failed';
        } catch (\Throwable $e) {
            // Timeouts, 5xx, authentication, a replay whose parameters
            // differ: the outcome is unknown, so keep the attempt as it is.
            return $this->unknown($attempt, $e);
        }

        return $this->applyIntent($attempt, $intent);
    }

    /** Maps a PaymentIntent's status onto the attempt. */
    private function applyIntent(PaymentAttempt $attempt, object $intent): string
    {
        switch ($intent->status) {
            case 'succeeded':
                AutopayOutcome::succeeded($attempt->id, $intent->id);

                return 'charged';

            case 'processing':
                AutopayOutcome::processing($attempt->id, $intent->id);

                return 'processing';

            case 'requires_payment_method':
            case 'canceled':
                AutopayOutcome::failed($attempt->id, $intent->id, $intent->last_payment_error->code ?? $intent->status, $intent->last_payment_error->message ?? null);

                return 'failed';

            default:
                // requires_action and friends: nobody is present to authenticate
                // an off-session charge, so this method cannot be used. Cancel
                // the dangling intent so it cannot be completed later.
                try {
                    $this->stripe->paymentIntents->cancel($intent->id);
                } catch (\Throwable $e) {
                    report($e);
                }
                AutopayOutcome::failed($attempt->id, $intent->id, $intent->status, 'The payment needs the customer to take action.');

                return 'failed';
        }
    }

    /** Asks Stripe for the current state of a debit that has been processing for days and records it. */
    private function reconcile(PaymentAttempt $attempt): string
    {
        try {
            $intent = $this->stripe->paymentIntents->retrieve($attempt->stripe_payment_intent_id);
        } catch (\Throwable $e) {
            return $this->unknown($attempt, $e);
        }

        if ($intent->status === 'processing') {
            return 'processing';
        }

        $this->applyIntent($attempt, $intent);

        return 'reconciled';
    }

    private function unknown(PaymentAttempt $attempt, \Throwable $e): string
    {
        Log::warning('An AutoPay charge has an unknown outcome and will be retried.', [
            'attempt_id' => $attempt->id,
            'payment_id' => $attempt->payment_id,
            'error' => $e->getMessage(),
        ]);

        return 'unknown';
    }

    private function flagForReview(Payment $payment, string $reason): void
    {
        Log::error('AutoPay charge needs manual review.', ['payment_id' => $payment->id, 'reason' => $reason]);
        Notification::send(PaymentStaff::recipients(), new AutopayNeedsReviewNotification($payment, $reason));
    }

    /** @return array{action: string, reason: string} */
    private function skip(string $reason): array
    {
        return ['action' => 'skip', 'reason' => $reason];
    }

    /**
     * The price of this attempt is fixed here, per charge: the stored bank
     * price, plus the card fee when the chosen method is a card.
     *
     * @param  array{type: string, id: string}  $method
     */
    private function chargeSpec(Payment $payment, string $kind, int $round, int $attemptNo, array $method): array
    {
        $bankCents = CardPricing::toCents((float) $payment->amount);
        $feeCents = CardPricing::feeCentsFor($bankCents, $method['type']);

        return [
            'action' => 'charge',
            'kind' => $kind,
            'round' => $round,
            'attempt_no' => $attemptNo,
            'method' => $method['type'],
            'payment_method_id' => $method['id'],
            'amount_cents' => $bankCents + $feeCents,
            'fee_cents' => $feeCents,
        ];
    }
}

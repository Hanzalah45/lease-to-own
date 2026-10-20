<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentPart;
use App\Models\User;
use App\Notifications\PaymentPartReceivedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\RateLimitException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Split payments (client, Joel, 2026-10-19 checklist, section 7; a real case: a customer who wanted to pay part of the deposit in cash
 * and the rest on a card). A payment keeps its full amount and is paid in pieces: cash, check or another method recorded by staff, or a
 * charge to a saved bank account or card for any amount up to what is left. The payment becomes paid when its paid pieces add up to its
 * amount, and then carries the total card fee and the method ("split" when the pieces used different ones).
 *
 * A charge for a piece is made the same careful way as every other charge here: the piece is recorded under a lock before Stripe is
 * asked, with an idempotency key made from its id, so a double press or a timeout never charges twice, and a call with no answer is
 * repeated or cancelled by a person, never replaced.
 */
class PaymentSplitter
{
    /** Kept on a piece whose call to Stripe got no answer. It marks the piece as one to look at, never to replace. */
    public const OUTCOME_UNKNOWN = 'No answer from the payment processor yet. It is not known whether this charge went through.';

    /** The kinds of payment that can be paid in pieces (an extra payment is already any amount the customer chooses). */
    public const TYPES = [Payment::TYPE_RENTAL, Payment::TYPE_LATE_FEE, Payment::TYPE_DEPOSIT, Payment::TYPE_PICKUP_BALANCE];

    /** What staff can record without a charge. */
    public const MANUAL_METHODS = ['cash', 'check', 'other'];

    private StripeClient $stripe;

    public function __construct(
        private readonly StripePaymentMethodService $paymentMethods,
        ?StripeClient $stripe = null,
    ) {
        $this->stripe = $stripe ?? new StripeClient(config('services.stripe.secret'));
    }

    /** Why this payment cannot take a piece right now, or null when it can. */
    public static function blocker(Payment $payment, bool $forCustomer = false): ?string
    {
        if (! in_array($payment->type, self::TYPES, true)) {
            return 'A payment of this kind cannot be paid in parts.';
        }
        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_FAILED], true)) {
            return 'This payment is not waiting to be paid.';
        }

        $lease = $payment->leaseAgreement()->with('customer')->first();
        if ($lease?->hasEnded()) {
            return 'This lease has ended.';
        }
        if ($forCustomer && $lease?->customer?->status === 'suspended') {
            return 'This account is suspended. Please contact us.';
        }

        // A charge on the payment itself (an AutoPay or "Pay now" attempt) is still on its way.
        $latest = $payment->attempts()->reorder()->orderByDesc('id')->first();
        if (($payment->stripe_payment_intent_id && $payment->status === Payment::STATUS_PENDING)
            || ($latest && in_array($latest->status, ['initiated', 'processing'], true))) {
            return 'A charge for this payment is already in flight.';
        }
        if ($payment->parts()->where('status', PaymentPart::STATUS_PENDING)->whereNull('stripe_payment_intent_id')->exists()) {
            return 'An earlier charge for a piece of this payment has no answer yet. Check Stripe, then cancel it here.';
        }

        return null;
    }

    /** A piece received by hand (cash, a check, another way): recorded as paid at once. */
    public function recordManual(Payment $payment, string $method, float $amount, User $staff, ?string $note = null): PaymentPart
    {
        abort_unless(in_array($method, self::MANUAL_METHODS, true), 422, 'Choose cash, check or another method.');
        $cents = (int) round($amount * 100);

        $part = DB::transaction(function () use ($payment, $method, $cents, $staff) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_if($reason = self::blocker($locked), 422, $reason);
            $this->assertAmount($locked, $cents);

            return $locked->parts()->create([
                'method' => $method,
                'amount' => round($cents / 100, 2),
                'card_fee_amount' => 0,
                'status' => PaymentPart::STATUS_PAID,
                'paid_date' => BillingClock::todayDate(),
                'recorded_by' => $staff->id,
                'started_by' => PaymentPart::BY_STAFF,
            ]);
        });

        AuditLog::record('split.part_recorded', sprintf('$%s received by %s toward this payment%s.', number_format((float) $part->amount, 2), $method, $note ? " ({$note})" : ''), [
            'payment' => $payment, 'amount' => $part->amount, 'method' => $method, 'actor' => $staff, 'details' => ['part_id' => $part->id, 'note' => $note],
        ]);
        $this->afterPaid($part, $staff->id);

        return $part->fresh();
    }

    /**
     * Charges a piece of this payment to a saved bank account or card. `$startedBy` is staff (off-session, after the customer agreed),
     * or the customer (present at their screen: a card may ask for an extra confirmation).
     *
     * @param  'bank'|'card'  $type
     *                               `$linkId` is the payment link the customer is paying from, if any: the piece remembers it so the link can say paid, and a link
     *                               made by staff for a given amount is not held to the minimum piece a customer picks for themselves.
     * @return array{part: PaymentPart, status: string, message: ?string, client_secret: ?string} status is paid, processing or requires_action
     */
    public function charge(Payment $payment, string $type, float $amount, ?int $expectedTotalCents, ?User $actor, string $startedBy, ?int $linkId = null): array
    {
        $cents = (int) round($amount * 100);
        $forCustomer = $startedBy === PaymentPart::BY_CUSTOMER;

        [$part, $methodId, $lease] = DB::transaction(function () use ($payment, $type, $cents, $expectedTotalCents, $actor, $startedBy, $forCustomer, $linkId) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_if($reason = self::blocker($locked, $forCustomer), 422, $reason);

            $lease = $locked->leaseAgreement()->with('customer')->firstOrFail();
            $method = $lease->paymentMethodFor($type);
            abort_unless($method, 422, $lease->methodRefusal($type));

            $this->assertAmount($locked, $cents, $forCustomer && ! $linkId);

            // Priced per charge in whole cents, and checked against the total the person saw on screen.
            $feeCents = CardPricing::feeCentsFor($cents, $method['type']);
            abort_if($expectedTotalCents !== null && $expectedTotalCents !== $cents + $feeCents, 409, 'The amount changed. Please review the new total and try again.');

            $part = $locked->parts()->create([
                'method' => $method['type'] === 'bank' ? 'ach' : 'card',
                'amount' => round($cents / 100, 2),
                'card_fee_amount' => $feeCents / 100,
                'status' => PaymentPart::STATUS_PENDING,
                'recorded_by' => $actor?->id,
                'started_by' => $startedBy,
                'payment_link_id' => $linkId,
            ]);

            return [$part, $method['id'], $lease];
        });

        AuditLog::record('split.part_charge_started', sprintf('A charge of $%s to the saved %s was started toward this payment.', number_format($part->totalCharged(), 2), $type === 'bank' ? 'bank account' : 'card'), [
            'payment' => $payment, 'amount' => $part->totalCharged(), 'method' => $part->method, 'actor' => $actor, 'details' => ['part_id' => $part->id],
        ]);

        try {
            $intent = $this->stripe->paymentIntents->create([
                'amount' => (int) round($part->totalCharged() * 100),
                'currency' => 'usd',
                'customer' => $this->paymentMethods->ensureStripeCustomer($lease->customer),
                'payment_method' => $methodId,
                'payment_method_types' => [$type === 'bank' ? 'us_bank_account' : 'card'],
                'confirm' => true,
                // A staff-run charge has no customer at a screen to approve a prompt, so it is declared off-session.
                ...($forCustomer ? [] : ['off_session' => true]),
                'description' => sprintf('Part of a %s, Lease #%d%s', str_replace('_', ' ', $payment->type), $lease->id, (float) $part->card_fee_amount > 0 ? ' (includes card processing fee)' : ''),
                'metadata' => ['payment_part_id' => $part->id, 'payment_id' => $payment->id, 'lease_id' => $lease->id, 'type' => 'payment_part'],
            ], ['idempotency_key' => 'payment-part-'.$part->id]);
        } catch (ApiErrorException $e) {
            // No answer, or a server error, says nothing about whether Stripe took the charge: the piece stays open for a person to settle.
            if ($this->outcomeIsUnknown($e)) {
                $part->update(['failure_message' => self::OUTCOME_UNKNOWN]);
                Log::warning('A charge for a piece of a payment got no answer from Stripe and is left open.', ['part_id' => $part->id, 'error' => $e->getMessage()]);

                abort(503, 'The payment processor did not answer, so it is not known whether the charge went through. Check Stripe before trying again: it is never charged twice.');
            }

            $reason = $e instanceof CardException ? $e->getMessage() : 'The payment could not be processed. Please try again, or contact us if it keeps failing.';
            if (! $e instanceof CardException) {
                Log::error('Stripe refused a charge for a piece of a payment.', ['part_id' => $part->id, 'error' => $e->getMessage()]);
            }
            $this->fail($part, $reason, $e->getError()->payment_intent->id ?? null);

            abort(422, $reason);
        }

        $part->update(['stripe_payment_intent_id' => $intent->id]);

        switch ($intent->status) {
            case 'succeeded':
                $this->settle($part);

                return $this->result($part, 'paid');

            case 'requires_action':
                if (! $forCustomer) {
                    $this->cancelQuietly($intent->id);
                    $this->fail($part, 'The customer has to approve this payment themselves, so it cannot be charged for them. Ask them to pay from their own account.', $intent->id);
                    abort(422, 'The customer has to approve this payment themselves, so it cannot be charged for them. Ask them to pay from their own account.');
                }

                return $this->result($part, 'requires_action', null, $intent->client_secret);

            case 'requires_payment_method':
            case 'canceled':
                $message = $intent->last_payment_error->message ?? 'The payment did not go through.';
                $this->fail($part, $message, $intent->id);
                abort(422, $message);

                // no break
            default:
                // processing: the normal bank debit path, settled by the webhook.
                return $this->result($part, 'processing');
        }
    }

    /** A piece's charge went through: it counts, and the payment is paid once the pieces add up. */
    public function settle(PaymentPart $part): PaymentPart
    {
        $settled = DB::transaction(function () use ($part) {
            $row = PaymentPart::whereKey($part->id)->lockForUpdate()->firstOrFail();
            if ($row->status === PaymentPart::STATUS_PAID) {
                return null;
            }
            $row->update(['status' => PaymentPart::STATUS_PAID, 'paid_date' => BillingClock::todayDate(), 'failure_message' => null]);

            return $row->fresh();
        });

        if ($settled) {
            AuditLog::record('split.part_paid', sprintf('A charge of $%s went through toward this payment.', number_format($settled->totalCharged(), 2)), [
                'payment' => $settled->payment, 'amount' => $settled->totalCharged(), 'method' => $settled->method, 'system' => true, 'details' => ['part_id' => $settled->id],
            ]);
            $this->afterPaid($settled, $settled->recorded_by);
            PaymentLinkService::partSettled($settled);
        }

        return $settled ?? $part->fresh();
    }

    /** A piece's charge did not go through: it counts for nothing and the money can be asked for again. */
    public function fail(PaymentPart $part, ?string $message, ?string $intentId = null): void
    {
        $row = PaymentPart::whereKey($part->id)->first();
        if (! $row || $row->status === PaymentPart::STATUS_PAID) {
            return;
        }
        $row->update([
            'status' => PaymentPart::STATUS_FAILED,
            'failure_message' => $message ? Str::limit($message, 500, '') : null,
            'stripe_payment_intent_id' => $row->stripe_payment_intent_id ?? $intentId,
        ]);
        PaymentLinkService::partFailed($row, $message);
        AuditLog::record('split.part_failed', sprintf('A charge of $%s toward this payment did not go through%s', number_format($row->totalCharged(), 2), $message ? ': '.Str::limit($message, 200, '') : '.'), [
            'payment' => $row->payment, 'amount' => $row->totalCharged(), 'method' => $row->method, 'system' => ! $row->recorded_by, 'details' => ['part_id' => $row->id],
        ]);
    }

    /** Takes back a piece whose charge Stripe never answered, once staff have checked in Stripe that it was not made. */
    public function cancelUnanswered(PaymentPart $part, User $staff): PaymentPart
    {
        abort_unless($part->status === PaymentPart::STATUS_PENDING && ! $part->stripe_payment_intent_id, 422, 'Only a charge that Stripe never answered can be cancelled here.');

        $part->update(['status' => PaymentPart::STATUS_FAILED, 'failure_message' => "Cancelled by {$staff->name}: Stripe never confirmed it."]);
        AuditLog::record('split.part_cancelled', 'A charge Stripe never answered was cancelled after checking Stripe.', [
            'payment' => $part->payment, 'amount' => $part->totalCharged(), 'actor' => $staff, 'details' => ['part_id' => $part->id],
        ]);

        return $part->fresh();
    }

    /**
     * Stripe reports on a payment intent. If it belongs to a piece (by its id, or by the piece number the call carried when the call never
     * got its answer), the piece is settled or failed here.
     *
     * @return bool true when the intent was a piece of a payment
     */
    public function applyIntent(PaymentIntent $intent, string $outcome): bool
    {
        $part = PaymentPart::where('stripe_payment_intent_id', $intent->id)->first();
        if (! $part) {
            $metadata = $intent->metadata ? $intent->metadata->toArray() : [];
            $part = ! empty($metadata['payment_part_id']) ? PaymentPart::whereKey($metadata['payment_part_id'])->whereNull('stripe_payment_intent_id')->first() : null;
            if (! $part) {
                return false;
            }
            $part->update(['stripe_payment_intent_id' => $intent->id]);
        }

        if ($outcome === 'paid') {
            $this->settle($part);
        } else {
            $this->fail($part, $intent->last_payment_error['message'] ?? null, $intent->id);
        }

        return true;
    }

    /**
     * A payment that has paid pieces is being marked paid in one go (the rest was charged the ordinary way, or staff marked it paid):
     * what is left becomes its last piece, so the pieces always add up to the payment. Returns the method to record the payment with
     * ("split" when the pieces used different ones), or null when the payment has no paid pieces.
     */
    public static function absorbFinalCharge(Payment $payment, ?string $method, ?int $recordedBy): ?string
    {
        $paid = $payment->parts()->where('status', PaymentPart::STATUS_PAID)->get();
        if ($paid->isEmpty()) {
            return null;
        }

        $remainder = round((float) $payment->amount - (float) $paid->sum('amount'), 2);
        if ($remainder > 0.004) {
            $lastMethod = in_array($method ?? $payment->method, ['ach', 'card', 'cash', 'check', 'other'], true) ? ($method ?? $payment->method) : 'other';
            $payment->parts()->create([
                'method' => $lastMethod,
                'amount' => $remainder,
                // The charger set the fee of the charge that paid the rest on the payment itself.
                'card_fee_amount' => $lastMethod === 'card' ? (float) $payment->card_fee_amount : 0.0,
                'status' => PaymentPart::STATUS_PAID,
                'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
                'paid_date' => BillingClock::todayDate(),
                'recorded_by' => $recordedBy,
                'started_by' => $recordedBy ? PaymentPart::BY_STAFF : PaymentPart::BY_SYSTEM,
            ]);
            // The charge now belongs to the piece, so refunds and Stripe events find the piece, not the payment.
            $payment->update(['stripe_payment_intent_id' => null]);
            $paid = $payment->parts()->where('status', PaymentPart::STATUS_PAID)->get();
        }

        $payment->update(['card_fee_amount' => round((float) $paid->sum('card_fee_amount'), 2)]);
        $methods = $paid->pluck('method')->unique();

        return $methods->count() === 1 ? (string) $methods->first() : 'split';
    }

    /** After a piece was paid: the payment is paid when the pieces add up, otherwise everyone hears what is still due. */
    private function afterPaid(PaymentPart $part, ?int $staffId): void
    {
        // The piece's receipt is written (and emailed) first, so it is there whether or not this piece finishes the payment.
        $receiptMailed = PaymentReceipts::onPartPaid($part);

        $payment = Payment::with('leaseAgreement.customer', 'leaseAgreement.application')->findOrFail($part->payment_id);

        $completed = DB::transaction(function () use ($payment, $staffId) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === Payment::STATUS_PAID) {
                return false;
            }
            $paid = $locked->parts()->where('status', PaymentPart::STATUS_PAID)->get();
            if (round((float) $locked->amount - (float) $paid->sum('amount'), 2) > 0.004) {
                return false;
            }

            $methods = $paid->pluck('method')->unique();
            $locked->update(['card_fee_amount' => round((float) $paid->sum('card_fee_amount'), 2)]);
            LeaseEngine::applyPaymentStatusChange($locked->fresh(), Payment::STATUS_PAID, $methods->count() === 1 ? (string) $methods->first() : 'split', $staffId);

            return true;
        });

        if ($completed) {
            return;
        }

        // Still owing: tell the customer what was received and what is left. A mail problem never undoes a payment.
        try {
            $payment->leaseAgreement->customer->notify(new PaymentPartReceivedNotification($payment, $part, ! $receiptMailed));
            Notification::send(PaymentStaff::recipients(), new PaymentPartReceivedNotification($payment, $part));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** A piece is for at least a cent and never more than what can still be charged; a customer's piece is not smaller than the minimum unless it is everything left. */
    private function assertAmount(Payment $payment, int $cents, bool $forCustomer = false): void
    {
        abort_if($cents < 1, 422, 'Enter an amount.');
        $left = (int) round($payment->chargeableAmount() * 100);
        abort_if($left < 1, 422, 'There is nothing left to pay on this payment.');
        abort_if($cents > $left, 422, sprintf('At most $%s can still be paid toward this payment.', number_format($left / 100, 2)));
        abort_if(
            $forCustomer && $cents < (int) round(PaymentPart::MIN_CUSTOMER_PIECE * 100) && $cents !== $left,
            422,
            sprintf('A part payment is at least $%s, unless it is everything that is left.', number_format(PaymentPart::MIN_CUSTOMER_PIECE, 2)),
        );
    }

    private function cancelQuietly(string $intentId): void
    {
        try {
            $this->stripe->paymentIntents->cancel($intentId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** True when Stripe may or may not have taken the charge: a timeout, a rate limit, a server error, or a repeated key it could not match. */
    private function outcomeIsUnknown(ApiErrorException $e): bool
    {
        return $e instanceof ApiConnectionException
            || $e instanceof RateLimitException
            || $e instanceof IdempotencyException
            || ($e->getHttpStatus() ?? 0) >= 500;
    }

    /** @return array{part: PaymentPart, status: string, message: ?string, client_secret: ?string} */
    private function result(PaymentPart $part, string $status, ?string $message = null, ?string $clientSecret = null): array
    {
        return ['part' => $part->fresh(), 'status' => $status, 'message' => $message, 'client_secret' => $clientSecret];
    }
}

<?php

namespace App\Services;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\PaymentLinkSend;
use App\Models\PaymentPart;
use App\Models\User;
use App\Notifications\PaymentLinkNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Payment links (client, Joel, 2026-10-19 checklist, section 6). Staff make a link for an amount, toward a payment that is due or as an
 * extra payment toward the early purchase price, and send it; the customer opens it on their phone, sees exactly what they pay, and pays
 * with a saved bank account or card. A link can be sent again (the old secret then stops working), cancelled, and it expires. Its status
 * follows what happens to it: sent, opened, processing, paid or failed.
 *
 * Only a hash of the secret is stored. The link itself is shown once, when it is made or sent, and goes in the email.
 */
class PaymentLinkService
{
    public function __construct(private readonly PaymentSplitter $splitter, private readonly CustomerPaymentCharger $charger) {}

    public static function urlFor(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/pay/'.$token;
    }

    /** The link a secret belongs to, or null. */
    public static function find(string $token): ?PaymentLink
    {
        return PaymentLink::where('token_hash', PaymentLink::hashOf($token))->first();
    }

    // ---- making, sending, ending ----

    /**
     * A link toward a payment that is due. The amount is the most it takes; it never takes more than is still due.
     *
     * @return array{link: PaymentLink, url: string}
     */
    public function createForPayment(Payment $payment, float $amount, User $staff): array
    {
        $cents = (int) round($amount * 100);
        abort_if($reason = PaymentSplitter::blocker($payment->loadMissing('leaseAgreement.customer')), 422, $reason);
        abort_if($cents < 1, 422, 'Enter an amount.');
        $chargeable = (int) round($payment->chargeableAmount() * 100);
        abort_if($cents > $chargeable, 422, sprintf('At most $%s is still due on this payment.', number_format($chargeable / 100, 2)));

        $label = str_replace('_', ' ', $payment->type === Payment::TYPE_PICKUP_BALANCE ? 'tracking fee and first month' : $payment->type);

        return $this->make($payment->leaseAgreement, PaymentLink::KIND_PAYMENT, $payment, round($cents / 100, 2), ucfirst($label === 'rental' ? 'monthly payment' : $label).' due '.($payment->due_date?->format('F j, Y') ?? 'now'), $staff);
    }

    /**
     * A link for an extra payment toward the early purchase price (contract Section 19, Additional Funds).
     *
     * @return array{link: PaymentLink, url: string}
     */
    public function createExtra(LeaseAgreement $lease, float $amount, User $staff): array
    {
        $lease->loadMissing('application', 'customer');
        abort_if($reason = CustomerPaymentCharger::extraPaymentBlocker($lease, false), 422, $reason);
        $options = CustomerPaymentCharger::extraPaymentOptions($lease);
        $amount = round($amount, 2);
        abort_if($amount < $options['min'] || $amount > $options['max'], 422, sprintf('Enter an amount between $%s and $%s.', number_format($options['min'], 2), number_format($options['max'], 2)));

        return $this->make($lease, PaymentLink::KIND_EXTRA, null, $amount, 'An extra payment toward your early purchase price', $staff);
    }

    /** @return array{link: PaymentLink, url: string} */
    private function make(LeaseAgreement $lease, string $kind, ?Payment $payment, float $amount, string $description, User $staff): array
    {
        $token = Str::random(48);
        $link = PaymentLink::create([
            'lease_agreement_id' => $lease->id,
            'kind' => $kind,
            'payment_id' => $payment?->id,
            'amount' => $amount,
            'description' => Str::limit($description, 200, ''),
            'token_hash' => PaymentLink::hashOf($token),
            'status' => PaymentLink::STATUS_CREATED,
            'expires_at' => now()->addDays(PaymentLink::LIFETIME_DAYS),
            'created_by' => $staff->id,
        ]);

        AuditLog::record('link.created', sprintf('A payment link for $%s was made: %s.', number_format($amount, 2), $description), [
            'lease' => $lease, 'payment' => $payment, 'amount' => $amount, 'actor' => $staff, 'details' => ['link_id' => $link->id, 'kind' => $kind],
        ]);

        return ['link' => $link, 'url' => self::urlFor($token)];
    }

    /**
     * Emails the link, now or again. Sending makes a new secret, so a link that was sent before stops working, and the day it expires is
     * counted from now. Returns the link, the new address of it (to copy), and whether the mail service took the message.
     *
     * @return array{link: PaymentLink, url: string, mailed: bool}
     */
    public function send(PaymentLink $link, string $email, User $staff): array
    {
        abort_if(in_array($link->status, [PaymentLink::STATUS_PAID, PaymentLink::STATUS_CANCELLED], true), 422, $link->status === PaymentLink::STATUS_PAID ? 'This link has already been paid.' : 'This link was cancelled.');
        abort_if($link->status === PaymentLink::STATUS_PROCESSING, 422, 'A payment from this link is still being processed.');

        $token = Str::random(48);
        $first = $link->send_count === 0;
        $link->update([
            'token_hash' => PaymentLink::hashOf($token),
            'sent_to' => $email,
            'status' => PaymentLink::STATUS_SENT,
            'expires_at' => now()->addDays(PaymentLink::LIFETIME_DAYS),
            'first_sent_at' => $link->first_sent_at ?? now(),
            'last_sent_at' => now(),
            'send_count' => $link->send_count + 1,
            'failure_message' => null,
        ]);
        $url = self::urlFor($token);

        $error = null;
        try {
            $link->loadMissing('leaseAgreement.customer');
            Notification::route('mail', $email)->notify(new PaymentLinkNotification($link, $url, $link->leaseAgreement->customer?->name));
        } catch (\Throwable $e) {
            report($e);
            $error = Str::limit($e->getMessage(), 300, '');
        }

        $link->sends()->create([
            'email' => $email,
            'sent_by' => $staff->id,
            'outcome' => $error ? PaymentLinkSend::OUTCOME_FAILED : PaymentLinkSend::OUTCOME_SENT,
            'error' => $error,
            'sent_at' => now(),
        ]);
        AuditLog::record($first ? 'link.sent' : 'link.resent', sprintf('The payment link was %s to %s%s.', $first ? 'emailed' : 'emailed again', $email, $error ? ', but the mail service refused it' : ''), [
            'lease' => $link->leaseAgreement, 'payment' => $link->payment, 'amount' => $link->amount, 'actor' => $staff, 'details' => ['link_id' => $link->id, 'email' => $email],
        ]);

        return ['link' => $link->fresh(), 'url' => $url, 'mailed' => $error === null];
    }

    /** Makes a link stop working. A link that was paid stays paid. */
    public function cancel(PaymentLink $link, User $staff): PaymentLink
    {
        abort_if($link->status === PaymentLink::STATUS_PAID, 422, 'This link has already been paid.');
        abort_if($link->status === PaymentLink::STATUS_PROCESSING, 422, 'A payment from this link is still being processed.');
        abort_if($link->status === PaymentLink::STATUS_CANCELLED, 422, 'This link was already cancelled.');

        // The secret goes too, so even a copied link no longer opens anything.
        $link->update(['status' => PaymentLink::STATUS_CANCELLED, 'cancelled_at' => now(), 'token_hash' => PaymentLink::hashOf(Str::random(48))]);
        AuditLog::record('link.cancelled', 'The payment link was cancelled.', [
            'lease' => $link->leaseAgreement, 'payment' => $link->payment, 'amount' => $link->amount, 'actor' => $staff, 'details' => ['link_id' => $link->id],
        ]);

        return $link->fresh();
    }

    // ---- what the customer sees and does ----

    /**
     * What the page shows. Opening it for the first time is recorded.
     *
     * @return array<string, mixed>
     */
    public function show(PaymentLink $link): array
    {
        $link->loadMissing('leaseAgreement.customer', 'payment');
        $lease = $link->leaseAgreement;

        if (! $link->opened_at && ! in_array($link->status, [PaymentLink::STATUS_PAID, PaymentLink::STATUS_CANCELLED], true)) {
            $link->update(['opened_at' => now(), 'status' => in_array($link->status, [PaymentLink::STATUS_CREATED, PaymentLink::STATUS_SENT], true) ? PaymentLink::STATUS_OPENED : $link->status]);
        }

        [$payable, $blocker] = $this->payableFor($link);
        $methods = $lease->usableMethodTypes();

        return [
            'customer_name' => $lease->customer?->name,
            'description' => $link->description,
            'kind' => $link->kind,
            'status' => $link->effectiveStatus(),
            'blocker' => $blocker,
            'amount' => $payable,
            'prices' => CardPricing::both($payable),
            'card_fee_bps' => CardPricing::rateBps(),
            'available_methods' => $methods,
            // A customer with nothing saved is sent to add a bank account or card first (the same signed page the AutoPay email uses).
            'setup_url' => $methods === [] && $lease->customer ? PaymentMethodSigner::urlFor($lease->customer, $lease) : null,
            'expires_at' => $link->expires_at,
        ];
    }

    /**
     * What the link can take now and why not, if it cannot: the amount it was made for, but never more than is still due.
     *
     * @return array{0: float, 1: ?string}
     */
    private function payableFor(PaymentLink $link): array
    {
        if ($link->status === PaymentLink::STATUS_PAID) {
            return [0.0, 'This link has already been paid. Thank you.'];
        }
        if ($link->status === PaymentLink::STATUS_CANCELLED) {
            return [0.0, 'This link is no longer valid. Please contact us.'];
        }
        if ($link->status === PaymentLink::STATUS_PROCESSING) {
            return [0.0, 'A payment from this link is being processed. You do not need to do anything.'];
        }
        if ($link->isExpired()) {
            return [0.0, 'This link has expired. Please contact us for a new one.'];
        }

        if ($link->kind === PaymentLink::KIND_PAYMENT) {
            $payment = $link->payment;
            if (! $payment || ($reason = PaymentSplitter::blocker($payment, true))) {
                return [0.0, $payment && $payment->status === Payment::STATUS_PAID ? 'This has already been paid. Thank you.' : ($reason ?? 'This payment cannot be paid with this link.')];
            }
            $payable = min((float) $link->amount, $payment->chargeableAmount());

            return $payable < 0.01 ? [0.0, 'There is nothing left to pay on this.'] : [round($payable, 2), null];
        }

        $lease = $link->leaseAgreement->loadMissing('application', 'customer');
        if ($reason = CustomerPaymentCharger::extraPaymentBlocker($lease)) {
            return [0.0, $reason];
        }
        $max = CustomerPaymentCharger::extraPaymentOptions($lease)['max'];

        return [round(min((float) $link->amount, $max), 2), null];
    }

    /**
     * The customer pays from the link, with a saved bank account or card, for the exact total they were shown.
     *
     * @return array{status: string, message: ?string, client_secret: ?string, link: array<string, mixed>}
     */
    public function pay(PaymentLink $link, ?string $methodType, ?int $expectedTotalCents): array
    {
        $link->loadMissing('leaseAgreement.customer', 'payment');
        [$payable, $blocker] = $this->payableFor($link);
        abort_if($blocker !== null, 422, $blocker);
        abort_unless(in_array($methodType, ['bank', 'card'], true), 422, 'Choose a bank account or a card.');

        $link->update(['failure_message' => null]);

        if ($link->kind === PaymentLink::KIND_PAYMENT) {
            // A piece that is refused (a declined card) marks the link failed itself (see partFailed); a refusal before any charge leaves it as it was.
            $result = $this->splitter->charge($link->payment, $methodType, $payable, $expectedTotalCents, null, PaymentPart::BY_CUSTOMER, $link->id);
            $status = $result['status'];
            $message = $result['message'];
            $secret = $result['client_secret'];
        } else {
            $result = $this->charger->payExtra($link->leaseAgreement, $payable, $methodType, $expectedTotalCents);
            $link->update(['payment_id' => $result['payment']->id]);
            $result['payment']->update(['payment_link_id' => $link->id]);
            $status = $result['status'];
            $message = $result['message'];
            $secret = $result['client_secret'];
        }

        $fresh = $link->fresh();
        if ($status === 'paid') {
            $this->markPaid($fresh);
        } elseif (in_array($status, ['processing', 'requires_action'], true)) {
            $fresh->update(['status' => PaymentLink::STATUS_PROCESSING]);
        } elseif ($status === 'failed') {
            $fresh->update(['status' => PaymentLink::STATUS_FAILED, 'failure_message' => $message ? Str::limit($message, 500, '') : 'The payment did not go through.']);
        }

        return ['status' => $status, 'message' => $message, 'client_secret' => $secret, 'link' => $this->show($link->fresh())];
    }

    // ---- what happens to the money afterwards ----

    /** The money for a link landed. */
    public static function markPaid(PaymentLink $link): void
    {
        if ($link->status === PaymentLink::STATUS_PAID) {
            return;
        }
        $link->update(['status' => PaymentLink::STATUS_PAID, 'paid_at' => now(), 'failure_message' => null]);
        AuditLog::record('link.paid', sprintf('The payment link was paid ($%s).', number_format((float) $link->amount, 2)), [
            'lease' => $link->leaseAgreement, 'payment' => $link->payment, 'amount' => $link->amount, 'system' => true, 'details' => ['link_id' => $link->id],
        ]);
    }

    /** A piece paid through a link went through. */
    public static function partSettled(PaymentPart $part): void
    {
        if ($part->payment_link_id && ($link = PaymentLink::find($part->payment_link_id))) {
            self::markPaid($link);
        }
    }

    /** A piece paid through a link did not go through (declined, or returned by the bank later): the link can be tried again. */
    public static function partFailed(PaymentPart $part, ?string $message): void
    {
        if ($part->payment_link_id && ($link = PaymentLink::find($part->payment_link_id)) && $link->status !== PaymentLink::STATUS_PAID) {
            $link->update(['status' => PaymentLink::STATUS_FAILED, 'failure_message' => $message ? Str::limit($message, 500, '') : 'The payment did not go through.']);
        }
    }

    /** An extra payment made through a link was paid, or failed. */
    public static function paymentOutcome(Payment $payment, bool $paid): void
    {
        if (! $payment->payment_link_id || ! ($link = PaymentLink::find($payment->payment_link_id))) {
            return;
        }
        if ($paid) {
            self::markPaid($link);
        } elseif ($link->status !== PaymentLink::STATUS_PAID) {
            $link->update(['status' => PaymentLink::STATUS_FAILED, 'failure_message' => $payment->failure_message ? Str::limit($payment->failure_message, 500, '') : 'The payment did not go through.']);
        }
    }
}

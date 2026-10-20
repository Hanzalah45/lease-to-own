<?php

namespace App\Services;

use App\Models\Application;
use App\Models\AutopayAgreement;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AutopayAgreedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The AutoPay tick box (client, Joel, 2026-10-16 asked for it, 2026-10-20 wrote the wording): a customer who signed without AutoPay turns
 * it on from the portal, by ticking the box next to the words below, instead of signing the contract again. The words and the terms shown
 * with them are kept with the agreement, like an addendum to the lease agreement ("added to my Lease Agreement and does not require an
 * additional signature"). A change to the words is a new WORDING_VERSION, so every agreement says exactly what it was given on.
 */
class AutopayAgreementService
{
    public const WORDING_VERSION = 1;

    public const WORDING = 'I agree to pay my monthly lease payments automatically using the bank account or card saved on my lease, as set out in the Autopay Lease Payment Authorization in my Lease Agreement. Each payment will be charged on its due date at the price for the payment method used (the card price includes the card processing fee). If my primary payment method fails, another saved method I have authorized may be charged. I may revoke Autopay by written notice at least three (3) business days before the next scheduled payment. I am not required to use Autopay to keep this lease. This authorization is added to my Lease Agreement and does not require an additional signature.';

    /**
     * Why this lease cannot turn AutoPay on from the portal, or null when it can. Only a lease already picked up: before pickup the
     * signing and delivery checklist work from the lease's AutoPay setting, which staff set in Lease Terms.
     */
    public static function blocker(LeaseAgreement $lease): ?string
    {
        if ($lease->autopay_enabled) {
            return 'AutoPay is already on for this lease.';
        }
        if ($lease->hasEnded()) {
            return 'This lease has ended.';
        }
        if ($lease->application?->status !== Application::STATUS_FINISHED) {
            return 'AutoPay can be turned on once the equipment has been picked up.';
        }
        if (! $lease->stripe_bank_payment_method_id && ! $lease->stripe_card_payment_method_id) {
            return 'Save a bank account or a card first.';
        }

        return null;
    }

    /**
     * What the customer is shown next to the tick box, or null when the box is not offered for this lease.
     *
     * @return array<string, mixed>|null
     */
    public function offer(LeaseAgreement $lease): ?array
    {
        $lease->loadMissing('application');
        if (self::blocker($lease) !== null) {
            return null;
        }

        $monthly = $lease->pricingSummary()['monthly'];
        $feePercent = rtrim(rtrim(number_format(CardPricing::ratePercent(), 2), '0'), '.');

        $next = $lease->payments()
            ->where('type', Payment::TYPE_RENTAL)
            ->where('status', Payment::STATUS_PENDING)
            ->whereDate('due_date', '>=', BillingClock::todayDate())
            ->orderBy('due_date')
            ->orderBy('id')
            ->first();

        return [
            'version' => self::WORDING_VERSION,
            'wording' => self::WORDING,
            'title' => 'Autopay Lease Payment Authorization',
            'amounts' => ['bank' => $monthly['bank'], 'card' => $monthly['card'], 'card_fee_percent' => (float) $feePercent],
            'billing_cycle' => BillingSchedule::cycleLabel($lease->billing_cycle),
            'first_payment' => $next ? [
                'due_date' => $next->due_date->toDateString(),
                'bank' => round((float) $next->amount, 2),
                'card' => CardPricing::both((float) $next->amount)['card'],
            ] : null,
            'terms' => [
                ['heading' => null, 'text' => 'By ticking the box below, you elect to make your monthly lease payments automatically as set forth in this Agreement, using the bank account or payment method saved on your lease.'],
                ['heading' => 'AutoPay Notice', 'text' => 'If a payment charged through AutoPay is more than $30 above the regular payment amount, you will receive at least ten (10) days\' notice before the additional amount is charged, as provided in this Agreement.'],
                ['heading' => 'Payment Method & Price', 'text' => 'Each Rental Payment will be charged to the AutoPay payment method you select as your primary payment method. The price applicable to the payment method used will apply to each payment.'],
                ['heading' => null, 'text' => "If a payment cannot be successfully collected from your primary payment method, AutoPay may charge another payment method you have authorized and provided, if one is available. If the alternate payment method is a credit or debit card, the applicable card payment price, including the {$feePercent}% card processing fee, will apply."],
                ['heading' => 'When AutoPay starts', 'text' => 'AutoPay starts with the first payment that falls due on or after the day you agree. A payment that was already due before that day stays payable from your Payments page and is not charged automatically.'],
                ['heading' => 'AutoPay Revocation', 'text' => 'You may revoke your AutoPay authorization by providing written notice at least three (3) business days before the next scheduled payment, subject to the terms and requirements of this Agreement.'],
                ['heading' => 'Acknowledgment', 'text' => 'You were not required to agree to this to keep your lease; you may arrange payment via an alternative method at any time.'],
            ],
        ];
    }

    /**
     * Records the agreement and turns AutoPay on, in one step.
     *
     * @param  int  $shownVersion  the wording version the customer was shown; refused when the words changed since
     * @param  string  $via  portal (logged in) or signed_link (the emailed link of a guest-originated customer)
     */
    public function agree(LeaseAgreement $lease, User $customer, Request $request, int $shownVersion, string $via): AutopayAgreement
    {
        $agreement = DB::transaction(function () use ($lease, $customer, $request, $shownVersion, $via) {
            $locked = LeaseAgreement::with('application')->whereKey($lease->id)->lockForUpdate()->firstOrFail();

            if ($reason = self::blocker($locked)) {
                abort(422, $reason);
            }
            if ($shownVersion !== self::WORDING_VERSION) {
                abort(422, 'The wording was updated. Please read it again and tick the box.');
            }

            $offer = $this->offer($locked);

            $agreement = AutopayAgreement::create([
                'lease_agreement_id' => $locked->id,
                'user_id' => $customer->id,
                'wording_version' => self::WORDING_VERSION,
                'wording' => self::WORDING,
                'authorization' => $offer,
                'via' => $via,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
                'agreed_at' => now(),
            ]);

            $locked->update([
                'autopay_enabled' => true,
                'autopay_paused_at' => null,
                'autopay_started_on' => BillingClock::todayDate(),
            ]);

            $locked->application->dealerNotes()->create([
                'author_user_id' => $customer->id,
                'text' => sprintf(
                    'The customer turned on AutoPay with the tick box on %s (Central time). Version %d of the wording was shown and kept with the agreement. AutoPay charges the first payment that falls due on or after that day.',
                    BillingClock::now()->format('M j, Y g:i A'),
                    self::WORDING_VERSION,
                ),
            ]);

            return $agreement;
        });

        AuditLog::record('autopay.turned_on', 'The customer agreed to AutoPay with the tick box (wording version '.self::WORDING_VERSION.').', [
            'lease' => $lease, 'actor' => $customer, 'details' => ['via' => $via, 'agreement_id' => $agreement->id],
        ]);

        // Outside the transaction, and caught: a mail problem must never undo or fail an agreement that was recorded.
        try {
            $notification = new AutopayAgreedNotification($lease->fresh(), $agreement);
            $customer->notify($notification);
            Notification::send(PaymentStaff::recipients(), $notification);
        } catch (\Throwable $e) {
            report($e);
        }

        return $agreement;
    }
}

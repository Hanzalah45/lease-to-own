<?php

namespace App\Models;

use App\Services\AdditionalFundsLedger;
use App\Services\StripeDepositPaymentService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    public const TYPE_RENTAL = 'rental';

    public const TYPE_LATE_FEE = 'late_fee';

    public const TYPE_DEPOSIT = 'deposit';

    /** The $150 tracking fee + first month's rent, deferred when a customer chooses "pay deposit only" (client, Joel, 2026-10-02) — never shares a PaymentIntent with the deposit row. */
    public const TYPE_PICKUP_BALANCE = 'pickup_balance';

    /**
     * An amount paid on top of the monthly payments, toward the early purchase price (contract Section 19, "Additional
     * Funds"). When it is paid it is added to the lease's additional_funds, which lowers the early purchase price; it is
     * never a rental payment, so it does not count as a month paid and never counts as arrears.
     */
    public const TYPE_EXTRA_PAYMENT = 'extra_payment';

    protected $fillable = [
        'lease_agreement_id',
        'type',
        'late_fee_for_payment_id',
        'amount',
        'card_fee_amount',
        'due_date',
        'paid_date',
        'method',
        'status',
        'failure_message',
        'recorded_by',
        'stripe_payment_intent_id',
        'quickbooks_record_id',
        'quickbooks_sync_status',
        'quickbooks_synced_at',
        'quickbooks_sync_error',
        'quickbooks_sync_attempts',
        'quickbooks_reversal_id',
        'payment_link_id',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'paid_date' => 'date',
            'amount' => 'decimal:2',
            'card_fee_amount' => 'decimal:2',
            'quickbooks_synced_at' => 'datetime',
        ];
    }

    /**
     * What the customer is actually charged: the bank price (`amount`) plus
     * the card fee when it was paid by card (dual pricing, 2026-10-05).
     * `amount` alone stays the bank price on purpose — see CardPricing.
     */
    public function totalCharged(): float
    {
        return round((float) $this->amount + (float) $this->card_fee_amount, 2);
    }

    /**
     * A deposit or pickup charge whose call to Stripe got no answer (see
     * StripeDepositPaymentService::OUTCOME_UNKNOWN): pending with no charge behind
     * it yet. The customer may press pay again, which repeats the same charge.
     */
    public function outcomeUnknown(): bool
    {
        return $this->status === self::STATUS_PENDING
            && ! $this->stripe_payment_intent_id
            && $this->failure_message === StripeDepositPaymentService::OUTCOME_UNKNOWN;
    }

    /** The few fields a customer sees about a deposit or pickup charge. */
    public function customerSummary(): array
    {
        return $this->only(['id', 'status', 'method', 'amount', 'card_fee_amount', 'paid_date', 'created_at'])
            + ['outcome_unknown' => $this->outcomeUnknown()];
    }

    public function leaseAgreement(): BelongsTo
    {
        return $this->belongsTo(LeaseAgreement::class);
    }

    /** The pieces this payment was paid in, oldest first, when it was not paid in one go (see PaymentPart). */
    public function parts(): HasMany
    {
        return $this->hasMany(PaymentPart::class)->orderBy('id');
    }

    /** What has been paid so far through pieces that went through (the bank price, card fees not counted). */
    public function paidPrincipal(): float
    {
        $parts = $this->relationLoaded('parts') ? $this->parts : $this->parts()->get();

        return round((float) $parts->where('status', PaymentPart::STATUS_PAID)->sum('amount'), 2);
    }

    /** What is still to be paid, as the customer reads it: the payment's price less the pieces that went through. */
    public function remainingAmount(): float
    {
        return max(0.0, round((float) $this->amount - $this->paidPrincipal(), 2));
    }

    /**
     * What a new charge for this payment may be for: the price less pieces that went through and pieces still on their way (a bank
     * debit takes days, and the same money must not be asked for twice).
     */
    public function chargeableAmount(): float
    {
        $parts = $this->relationLoaded('parts') ? $this->parts : $this->parts()->get();
        $taken = (float) $parts->whereIn('status', [PaymentPart::STATUS_PAID, PaymentPart::STATUS_PENDING])->sum('amount');

        return max(0.0, round((float) $this->amount - $taken, 2));
    }

    /** True when this payment has been paid, or is being paid, in pieces. */
    public function isSplit(): bool
    {
        $parts = $this->relationLoaded('parts') ? $this->parts : $this->parts()->get();

        return $parts->whereIn('status', [PaymentPart::STATUS_PAID, PaymentPart::STATUS_PENDING])->isNotEmpty();
    }

    /** Money returned for this payment, in full or in part, oldest first (see PaymentRefund). */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class)->orderBy('id');
    }

    /** What staff wrote about this payment (never shown to customers). */
    public function notes(): HasMany
    {
        return $this->hasMany(PaymentNote::class)->orderBy('id');
    }

    /** Chargebacks reported against this payment. */
    public function disputes(): HasMany
    {
        return $this->hasMany(PaymentDispute::class)->orderBy('id');
    }

    /** What has been refunded or is on its way back, card fee included (a failed refund counts for nothing). */
    public function refundedTotal(): float
    {
        $refunds = $this->relationLoaded('refunds') ? $this->refunds : $this->refunds()->get();

        return round((float) $refunds->whereIn('status', PaymentRefund::COUNTING_STATUSES)->sum('amount'), 2);
    }

    /** What can still be refunded of this payment. */
    public function refundableAmount(): float
    {
        if ($this->status !== self::STATUS_PAID) {
            return 0.0;
        }

        $left = max(0.0, round($this->totalCharged() - $this->refundedTotal(), 2));

        // An extra payment can only give back what is still held as Additional Funds: money already applied to repairs is not
        // the customer's any more, so it cannot be refunded a second time through the payment (contract Section 19).
        if ($this->type === self::TYPE_EXTRA_PAYMENT && (float) $this->amount > 0 && $this->leaseAgreement) {
            $held = AdditionalFundsLedger::available($this->leaseAgreement) * ($this->totalCharged() / (float) $this->amount);
            $left = min($left, max(0.0, round($held, 2)));
        }

        return $left;
    }

    /** The refund figures travel with a payment whose refunds were loaded (the staff screens), so a list never asks the database once per row. */
    public function toArray(): array
    {
        $array = parent::toArray();
        if ($this->relationLoaded('refunds')) {
            $array['refunded_total'] = $this->refundedTotal();
            $array['refundable_amount'] = $this->refundableAmount();
            $array['applied_to'] = $this->appliedTo();
        }
        // Pieces travel with the payment wherever they were loaded; what is left to pay comes with them.
        if ($this->relationLoaded('parts')) {
            $array['remaining_amount'] = $this->remainingAmount();
            $array['chargeable_amount'] = $this->chargeableAmount();
        }

        return $array;
    }

    /**
     * What a payment is applied to (client, Joel, 2026-10-19 checklist, section 10): a monthly payment to rent, a late fee to fees, an
     * extra payment to the early purchase price through the Additional Funds, the deposit and the tracking fee to themselves. Money
     * the Additional Funds later paid for repairs is shown on the funds history, not here.
     */
    public function appliedTo(): string
    {
        return match ($this->type) {
            self::TYPE_RENTAL => 'rent',
            self::TYPE_LATE_FEE => 'fees',
            self::TYPE_EXTRA_PAYMENT => 'epo',
            self::TYPE_DEPOSIT => 'deposit',
            self::TYPE_PICKUP_BALANCE => 'tracking_fee_and_first_month',
            default => 'other',
        };
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function riskRedFlags(): HasMany
    {
        return $this->hasMany(RiskRedFlag::class);
    }

    /** Every try at collecting this payment through Stripe, oldest first (automatic monthly charging only). */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('attempt_no');
    }

    /** Set only on a late_fee row: the overdue rental payment it was charged against. */
    public function lateFeeForPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'late_fee_for_payment_id');
    }

    /** The late_fee row charged against this rental payment, if any. */
    public function lateFeeCharge(): HasOne
    {
        return $this->hasOne(Payment::class, 'late_fee_for_payment_id');
    }
}

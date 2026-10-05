<?php

namespace App\Models;

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
        'recorded_by',
        'stripe_payment_intent_id',
        'quickbooks_record_id',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'paid_date' => 'date',
            'amount' => 'decimal:2',
            'card_fee_amount' => 'decimal:2',
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

    public function leaseAgreement(): BelongsTo
    {
        return $this->belongsTo(LeaseAgreement::class);
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

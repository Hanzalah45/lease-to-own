<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One piece of a payment that is paid in more than one (see the create_payment_parts_table migration).
 */
class PaymentPart extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    /** A part taken back whole from a payment that was still open: it no longer counts as paid. */
    public const STATUS_REFUNDED = 'refunded';

    public const BY_STAFF = 'staff';

    public const BY_CUSTOMER = 'customer';

    public const BY_SYSTEM = 'system';

    /** The smallest piece a customer can pay by card or bank account on their own, unless it is everything that is left. */
    public const MIN_CUSTOMER_PIECE = 5.00;

    /** The Stripe charge behind a piece is for the server only. */
    protected $hidden = ['stripe_payment_intent_id'];

    protected $fillable = [
        'payment_id',
        'method',
        'amount',
        'card_fee_amount',
        'status',
        'stripe_payment_intent_id',
        'failure_message',
        'paid_date',
        'recorded_by',
        'started_by',
        'payment_link_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'card_fee_amount' => 'decimal:2',
            'paid_date' => 'date',
        ];
    }

    /** What went through for this piece: its price plus its card fee. */
    public function totalCharged(): float
    {
        return round((float) $this->amount + (float) $this->card_fee_amount, 2);
    }

    /** The part with what can still be refunded of it, when its refunds were loaded. */
    public function toArray(): array
    {
        $array = parent::toArray();
        if ($this->relationLoaded('refunds')) {
            $array['refunded_total'] = $this->refundedTotal();
            $array['refundable_amount'] = $this->refundableAmount();
        }

        return $array;
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Refunds made against this piece, oldest first. */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class)->orderBy('id');
    }

    /** What has been refunded of this piece, or is on its way back. */
    public function refundedTotal(): float
    {
        $refunds = $this->relationLoaded('refunds') ? $this->refunds : $this->refunds()->get();

        return round((float) $refunds->whereIn('status', PaymentRefund::COUNTING_STATUSES)->sum('amount'), 2);
    }

    /** What can still be refunded of this piece (a piece that was not paid has nothing to give back). */
    public function refundableAmount(): float
    {
        return $this->status === self::STATUS_PAID ? max(0.0, round($this->totalCharged() - $this->refundedTotal(), 2)) : 0.0;
    }
}

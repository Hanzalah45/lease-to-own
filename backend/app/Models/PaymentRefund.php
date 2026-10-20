<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money returned to a customer for one payment, in full or in part. See the create_payment_refunds_and_disputes_tables migration.
 */
class PaymentRefund extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const SOURCE_PORTAL = 'portal';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_STRIPE = 'stripe';

    public const SOURCE_DISPUTE = 'dispute';

    /** Why staff can give for a refund made in the portal (the list the refund window offers). */
    public const REASONS = [
        'customer_request' => 'The customer asked for a refund',
        'overpayment' => 'The customer paid too much',
        'duplicate' => 'The customer was charged twice',
        'posted_in_error' => 'The payment was recorded in error',
        'returned_equipment' => 'The equipment was returned',
        'other' => 'Other, explained in the note',
    ];

    /** The reasons the system itself gives: a refund seen in Stripe, and a chargeback that was lost. */
    public const REASON_STRIPE = 'refunded_in_stripe';

    public const REASON_CHARGEBACK = 'chargeback';

    /** Refunds that are made or on their way, which is what counts against how much of a payment can still be refunded. */
    public const COUNTING_STATUSES = [self::STATUS_PENDING, self::STATUS_SUCCEEDED];

    protected $fillable = [
        'payment_id',
        'payment_part_id',
        'amount',
        'reason',
        'note',
        'source',
        'status',
        'stripe_refund_id',
        'failure_message',
        'refunded_by',
        'refunded_at',
        'quickbooks_record_id',
        'quickbooks_sync_status',
        'quickbooks_synced_at',
        'quickbooks_sync_error',
        'quickbooks_sync_attempts',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refunded_at' => 'datetime',
            'quickbooks_synced_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** The part of a split payment this refund came out of (its own card or bank account), when the payment was paid in parts. */
    public function part(): BelongsTo
    {
        return $this->belongsTo(PaymentPart::class, 'payment_part_id');
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function scopeCounting(Builder $query): Builder
    {
        return $query->whereIn('status', self::COUNTING_STATUSES);
    }

    /** What a person reads for the reason. */
    public function reasonLabel(): string
    {
        return match ($this->reason) {
            self::REASON_STRIPE => 'Refunded in Stripe',
            self::REASON_CHARGEBACK => 'Chargeback lost',
            default => self::REASONS[$this->reason] ?? $this->reason,
        };
    }
}

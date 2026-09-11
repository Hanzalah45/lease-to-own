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

    protected $fillable = [
        'lease_agreement_id',
        'type',
        'late_fee_for_payment_id',
        'amount',
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
        ];
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

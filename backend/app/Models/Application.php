<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Application extends Model
{
    use HasFactory;

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_NEEDS_INFO = 'needs_info';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FUNDED_PAID = 'funded_paid';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const ALL_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_NEEDS_INFO,
        self::STATUS_APPROVED,
        self::STATUS_COMPLETED,
        self::STATUS_PROCESSED,
        self::STATUS_FUNDED_PAID,
        self::STATUS_DECLINED,
        self::STATUS_WITHDRAWN,
    ];

    protected $fillable = [
        'customer_id',
        'status',
        'status_notes',
        'signature_received',
        'deposit_received',
        'reviewed_by',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'signature_received' => 'boolean',
            'deposit_received' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function leaseAgreement(): HasOne
    {
        return $this->hasOne(LeaseAgreement::class);
    }

    public function isReadyToAdvance(): bool
    {
        return $this->signature_received && $this->deposit_received;
    }
}

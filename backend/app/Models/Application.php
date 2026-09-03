<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * Which status a given status is allowed to move to next, mirroring what
     * the admin UI's own buttons already only ever do (see FLOW/PRIMARY_LABEL
     * in the application detail page). Without this, update() accepted any
     * status value regardless of the application's current one — a direct
     * API call could jump straight to funded_paid or resurrect a declined
     * application without going through decline's reversible "Change Status"
     * path.
     */
    public const LEGAL_STATUS_TRANSITIONS = [
        // includes APPROVED directly — the admin applications list page has a
        // one-click "Accept" on submitted rows that skips under_review entirely.
        self::STATUS_SUBMITTED => [self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED, self::STATUS_NEEDS_INFO, self::STATUS_DECLINED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_APPROVED, self::STATUS_NEEDS_INFO, self::STATUS_DECLINED],
        self::STATUS_NEEDS_INFO => [self::STATUS_UNDER_REVIEW, self::STATUS_DECLINED],
        self::STATUS_APPROVED => [self::STATUS_COMPLETED, self::STATUS_DECLINED],
        self::STATUS_COMPLETED => [self::STATUS_PROCESSED, self::STATUS_DECLINED],
        self::STATUS_PROCESSED => [self::STATUS_FUNDED_PAID, self::STATUS_DECLINED],
        self::STATUS_FUNDED_PAID => [],
        self::STATUS_DECLINED => [self::STATUS_SUBMITTED],
        self::STATUS_WITHDRAWN => [],
    ];

    protected $fillable = [
        'customer_id',
        'created_by',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leaseAgreement(): HasOne
    {
        return $this->hasOne(LeaseAgreement::class);
    }

    public function dealerNotes(): HasMany
    {
        return $this->hasMany(DealerNote::class)->latest();
    }

    public function infoRequests(): HasMany
    {
        return $this->hasMany(ApplicationInfoRequest::class)->latest();
    }

    public function isReadyToAdvance(): bool
    {
        return $this->signature_received && $this->deposit_received;
    }
}

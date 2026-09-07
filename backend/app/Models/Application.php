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

    // 2026-09-04: replaced with the client's 6-stage flow (Slack thread) —
    // waiting review -> waiting on customer approval (the phone call) ->
    // in verification/background check (admin-triggered, not automatic) ->
    // waiting on deposit -> waiting on delivery -> finished. needs_info stays
    // as a side-branch off the review stage; declined/withdrawn are unchanged
    // end states.
    public const STATUS_WAITING_REVIEW = 'waiting_review';
    public const STATUS_NEEDS_INFO = 'needs_info';
    public const STATUS_WAITING_APPROVAL = 'waiting_approval';
    public const STATUS_IN_VERIFICATION = 'in_verification';
    public const STATUS_WAITING_DEPOSIT = 'waiting_deposit';
    public const STATUS_WAITING_DELIVERY = 'waiting_delivery';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const ALL_STATUSES = [
        self::STATUS_WAITING_REVIEW,
        self::STATUS_NEEDS_INFO,
        self::STATUS_WAITING_APPROVAL,
        self::STATUS_IN_VERIFICATION,
        self::STATUS_WAITING_DEPOSIT,
        self::STATUS_WAITING_DELIVERY,
        self::STATUS_FINISHED,
        self::STATUS_DECLINED,
        self::STATUS_WITHDRAWN,
    ];

    /**
     * Which status a given status is allowed to move to next, mirroring what
     * the admin UI's own buttons already only ever do (see FLOW/PRIMARY_LABEL
     * in the application detail page). Without this, update() accepted any
     * status value regardless of the application's current one — a direct
     * API call could jump straight to finished or resurrect a declined
     * application without going through decline's reversible "Change Status"
     * path.
     */
    public const LEGAL_STATUS_TRANSITIONS = [
        self::STATUS_WAITING_REVIEW => [self::STATUS_WAITING_APPROVAL, self::STATUS_NEEDS_INFO, self::STATUS_DECLINED],
        self::STATUS_NEEDS_INFO => [self::STATUS_WAITING_REVIEW, self::STATUS_DECLINED],
        // The phone call: customer agrees to price/term/deposit before any
        // verification is triggered (client was explicit this must not be
        // automatic — see RiskProfileController's manual trigger actions).
        self::STATUS_WAITING_APPROVAL => [self::STATUS_IN_VERIFICATION, self::STATUS_DECLINED],
        self::STATUS_IN_VERIFICATION => [self::STATUS_WAITING_DEPOSIT, self::STATUS_DECLINED],
        self::STATUS_WAITING_DEPOSIT => [self::STATUS_WAITING_DELIVERY, self::STATUS_DECLINED],
        self::STATUS_WAITING_DELIVERY => [self::STATUS_FINISHED, self::STATUS_DECLINED],
        self::STATUS_FINISHED => [],
        self::STATUS_DECLINED => [self::STATUS_WAITING_REVIEW],
        self::STATUS_WITHDRAWN => [],
    ];

    protected $fillable = [
        'customer_id',
        'created_by',
        'status',
        'status_notes',
        'signature_received',
        'deposit_received',
        'deposit_hold_expires_at',
        'deposit_forfeited_at',
        'reviewed_by',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'signature_received' => 'boolean',
            'deposit_received' => 'boolean',
            'deposit_hold_expires_at' => 'datetime',
            'deposit_forfeited_at' => 'datetime',
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

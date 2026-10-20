<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A link a customer pays from (see the create_payment_links_tables migration). The secret in the link is never stored, only its hash.
 */
class PaymentLink extends Model
{
    public const KIND_PAYMENT = 'payment';

    public const KIND_EXTRA = 'extra';

    public const STATUS_CREATED = 'created';

    public const STATUS_SENT = 'sent';

    public const STATUS_OPENED = 'opened';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** What a status reads as once the link is past its day (a paid or cancelled link keeps its own). */
    public const STATUS_EXPIRED = 'expired';

    /** How long a link works for, from when it was made or last sent. */
    public const LIFETIME_DAYS = 14;

    protected $fillable = [
        'lease_agreement_id',
        'kind',
        'payment_id',
        'amount',
        'description',
        'token_hash',
        'sent_to',
        'status',
        'expires_at',
        'first_sent_at',
        'last_sent_at',
        'send_count',
        'opened_at',
        'paid_at',
        'cancelled_at',
        'failure_message',
        'created_by',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'first_sent_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    public function leaseAgreement(): BelongsTo
    {
        return $this->belongsTo(LeaseAgreement::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(PaymentLinkSend::class)->orderBy('id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast() && ! in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED], true);
    }

    /** The status as a person should read it: expired once past its day, unless it was paid or cancelled (or a charge is still on its way). */
    public function effectiveStatus(): string
    {
        return $this->isExpired() && $this->status !== self::STATUS_PROCESSING ? self::STATUS_EXPIRED : $this->status;
    }

    /** True while the link can still be paid from. */
    public function isUsable(): bool
    {
        return ! in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED, self::STATUS_PROCESSING], true) && ! $this->isExpired();
    }

    public function toArray(): array
    {
        return parent::toArray() + ['effective_status' => $this->effectiveStatus()];
    }
}

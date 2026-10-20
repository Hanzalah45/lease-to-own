<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to turn AutoPay off, and what became of it. See the create_autopay_revocations_table migration.
 */
class AutopayRevocation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'lease_agreement_id',
        'user_id',
        'requested_at',
        'effective_on',
        'via',
        'ip_address',
        'user_agent',
        'status',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'effective_on' => 'date',
            'resolved_at' => 'datetime',
        ];
    }

    public function leaseAgreement(): BelongsTo
    {
        return $this->belongsTo(LeaseAgreement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

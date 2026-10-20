<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the payment audit trail: who did what to which payment, when, with what method, from where. Rows are only added, never
 * changed (see the create_payment_audit_logs_and_notes_tables migration), so there is no updated_at and no fillable setter for it.
 */
class PaymentAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'actor_type',
        'actor_name',
        'action',
        'lease_agreement_id',
        'payment_id',
        'method',
        'amount',
        'summary',
        'details',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function leaseAgreement(): BelongsTo
    {
        return $this->belongsTo(LeaseAgreement::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of a lease's Additional Funds balance (contract Section 19). See the create_additional_funds_entries_table migration.
 */
class AdditionalFundsEntry extends Model
{
    public const KIND_ADDED = 'added';

    public const KIND_REVERSED = 'reversed';

    public const KIND_REFUNDED = 'refunded';

    public const KIND_REPAIRS = 'repairs';

    public const KIND_ADJUSTED = 'adjusted';

    protected $fillable = [
        'lease_agreement_id',
        'payment_id',
        'payment_refund_id',
        'kind',
        'amount',
        'balance_after',
        'description',
        'created_by',
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
            'balance_after' => 'decimal:2',
            'quickbooks_synced_at' => 'datetime',
        ];
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
}

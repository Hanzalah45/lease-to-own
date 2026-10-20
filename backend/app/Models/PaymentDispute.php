<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chargeback Stripe reported against a payment. See the create_payment_refunds_and_disputes_tables migration.
 */
class PaymentDispute extends Model
{
    protected $fillable = [
        'payment_id',
        'stripe_dispute_id',
        'amount',
        'reason',
        'status',
        'evidence_due_by',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'evidence_due_by' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A numbered receipt for money that came in or went back (see the create_payment_receipts_tables migration). Written once and never changed.
 */
class PaymentReceipt extends Model
{
    public const KIND_PAYMENT = 'payment';

    public const KIND_REFUND = 'refund';

    protected $fillable = [
        'receipt_number',
        'source_key',
        'kind',
        'lease_agreement_id',
        'payment_id',
        'payment_part_id',
        'payment_refund_id',
        'amount',
        'details',
        'pdf_path',
        'pdf_sha256',
        'issued_at',
    ];

    /** The stored copy's location and fingerprint are the portal's business, not something a screen needs. */
    protected $hidden = ['pdf_path', 'pdf_sha256'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'details' => 'array',
            'issued_at' => 'datetime',
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

    public function part(): BelongsTo
    {
        return $this->belongsTo(PaymentPart::class, 'payment_part_id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(PaymentRefund::class, 'payment_refund_id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(PaymentReceiptSend::class)->orderBy('id');
    }

    public static function formatNumber(int $id): string
    {
        return 'R-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public function toArray(): array
    {
        return parent::toArray() + ['has_copy' => $this->pdf_path !== null];
    }
}

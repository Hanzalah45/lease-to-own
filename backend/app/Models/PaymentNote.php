<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What staff write about one payment. Never shown to customers.
 */
class PaymentNote extends Model
{
    public const KIND_GENERAL = 'general';

    public const KIND_FAILED = 'failed';

    public const KIND_REFUND = 'refund';

    public const KIND_CHARGEBACK = 'chargeback';

    public const KINDS = [
        self::KIND_GENERAL => 'Note',
        self::KIND_FAILED => 'Failed payment',
        self::KIND_REFUND => 'Refund',
        self::KIND_CHARGEBACK => 'Chargeback',
    ];

    protected $fillable = [
        'payment_id',
        'kind',
        'body',
        'author_user_id',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}

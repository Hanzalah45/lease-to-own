<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One try at collecting a scheduled rental payment through Stripe — see
 * AutopayCharger and the create_payment_attempts_table migration.
 */
class PaymentAttempt extends Model
{
    public const STATUS_INITIATED = 'initiated';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'payment_id',
        'attempt_no',
        'round',
        'method',
        'amount_cents',
        'fee_cents',
        'stripe_payment_method_id',
        'idempotency_key',
        'stripe_payment_intent_id',
        'status',
        'failure_code',
        'failure_message',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** The Payment.method value ('ach' or 'card') this attempt's method corresponds to. */
    public function paymentMethodLabel(): string
    {
        return $this->method === 'bank' ? 'ach' : 'card';
    }
}

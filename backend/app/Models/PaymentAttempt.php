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

    /**
     * The idempotency key of a charge the customer started themselves ("Pay now", see AutopayCharger::payNow) begins
     * with this. It is how such an attempt is told apart from an automatic one: it is made with the customer present,
     * and a failure never falls back to the other method on its own.
     */
    public const CUSTOMER_KEY_PREFIX = 'customer-pay-';

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

    /** True when the customer pressed "Pay now" for this attempt (as opposed to AutoPay charging it). */
    public function startedByCustomer(): bool
    {
        return str_starts_with((string) $this->idempotency_key, self::CUSTOMER_KEY_PREFIX);
    }

    /** The Payment.method value ('ach' or 'card') this attempt's method corresponds to. */
    public function paymentMethodLabel(): string
    {
        return $this->method === 'bank' ? 'ach' : 'card';
    }
}

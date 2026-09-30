<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per Stripe event id ever processed — Stripe can and does redeliver
 * the same event (a slow response, a transient 5xx, a manual dashboard
 * retry), and this table is what turns a retried delivery into a no-op
 * instead of double-applying a payment status change. See
 * StripeWebhookController.
 */
class StripeWebhookEvent extends Model
{
    protected $fillable = [
        'stripe_event_id',
        'type',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}

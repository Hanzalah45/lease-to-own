<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time a payment link was emailed: to which address, when, by whom, and whether the mail service took it.
 */
class PaymentLinkSend extends Model
{
    public const OUTCOME_SENT = 'sent';

    public const OUTCOME_FAILED = 'failed';

    protected $fillable = [
        'payment_link_id',
        'email_log_id',
        'email',
        'sent_by',
        'outcome',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(PaymentLink::class, 'payment_link_id');
    }

    /** The email log row of this email: what the mailbox provider did with it. */
    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time a receipt was emailed: to which address, when, automatically or by whom, and whether the mail service took it.
 */
class PaymentReceiptSend extends Model
{
    public const TRIGGER_AUTO = 'auto';

    public const TRIGGER_MANUAL = 'manual';

    public const OUTCOME_SENT = 'sent';

    public const OUTCOME_FAILED = 'failed';

    protected $fillable = [
        'payment_receipt_id',
        'email_log_id',
        'email',
        'trigger',
        'sent_by',
        'outcome',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PaymentReceipt::class, 'payment_receipt_id');
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One email the system sent to one address. See the create_email_logs_table
 * migration and App\Services\EmailLogger.
 */
class EmailLog extends Model
{
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'to_address',
        'subject',
        'kind',
        'status',
        'error',
        'provider_message_id',
    ];

    /** @var list<string> */
    protected $appends = ['kind_label'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** What staff see for each kind of email; anything not listed falls back to its class name in words. */
    private const KIND_LABELS = [
        'RequestContractSignatureNotification' => 'Contract signing link',
        'ContractSignedNotification' => 'Contract signed',
        'ContractVoidedNotification' => 'Contract voided',
        'PaymentMethodsRequestedNotification' => 'AutoPay setup link',
        'ActivateAccountNotification' => 'Account setup link',
        'VerifyEmailNotification' => 'Email verification',
        'PaymentStatusChangedNotification' => 'Payment update',
        'AutopayPaymentReminderNotification' => 'Upcoming payment reminder',
        'ApplicationStatusChangedNotification' => 'Application status update',
        'AccountSecurityUpdatedNotification' => 'Account change notice',
        'LateFeeChargedNotification' => 'Late fee notice',
        'RequestBankVerificationNotification' => 'Bank verification request',
        'ApplicationInfoRequestedNotification' => 'More information requested',
        'AdminAccountCreatedMail' => 'Admin account created',
    ];

    public static function kindLabel(?string $kind): string
    {
        if (! $kind) {
            return 'Email';
        }

        return self::KIND_LABELS[$kind] ?? (string) Str::of($kind)->replaceMatches('/(Notification|Mail)$/', '')->headline();
    }

    public function getKindLabelAttribute(): string
    {
        return self::kindLabel($this->kind);
    }
}

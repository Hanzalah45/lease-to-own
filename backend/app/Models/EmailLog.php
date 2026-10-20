<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
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
        'ses_message_id',
        'delivery_status',
        'delivered_at',
        'delivery_detail',
        'delivery_updated_at',
    ];

    /** The id Amazon SES gave the message is how its events are matched: nobody on a screen needs it. */
    protected $hidden = ['ses_message_id'];

    /** @var list<string> */
    protected $appends = ['kind_label'];

    /** What Amazon SES reports after it accepted a message (see SesEventProcessor). */
    public const DELIVERY_DELAYED = 'delayed';

    public const DELIVERY_DELIVERED = 'delivered';

    public const DELIVERY_COMPLAINT = 'complaint';

    public const DELIVERY_BOUNCED = 'bounced';

    public const DELIVERY_REJECTED = 'rejected';

    /** Which result wins when several arrive: a held-up message that then arrives is delivered, a delivered one that is marked as spam is a complaint, and a bounce is final. */
    private const DELIVERY_RANK = [
        self::DELIVERY_DELAYED => 1,
        self::DELIVERY_DELIVERED => 2,
        self::DELIVERY_COMPLAINT => 3,
        self::DELIVERY_BOUNCED => 4,
        self::DELIVERY_REJECTED => 4,
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'delivery_updated_at' => 'datetime',
        ];
    }

    /**
     * Writes a delivery result unless a stronger one is already there. Events can arrive twice or out of order, so the same news again
     * changes nothing. Returns true when the row changed.
     */
    public function recordDelivery(string $status, ?Carbon $at = null, ?string $detail = null): bool
    {
        $current = self::DELIVERY_RANK[$this->delivery_status] ?? 0;
        if ((self::DELIVERY_RANK[$status] ?? 0) < $current) {
            return false;
        }
        if ($this->delivery_status === $status && ($detail === null || $detail === $this->delivery_detail)) {
            return false;
        }

        $this->forceFill([
            'delivery_status' => $status,
            'delivered_at' => $status === self::DELIVERY_DELIVERED ? ($at ?? now()) : $this->delivered_at,
            'delivery_detail' => $detail !== null ? Str::limit($detail, 500, '') : $this->delivery_detail,
            'delivery_updated_at' => now(),
        ])->save();

        return true;
    }

    /** The email log row of the email just sent to an address, found by who it went to and what it was. Null when there is none. */
    public static function latestFor(string $address, string $kind): ?self
    {
        return self::query()
            ->whereRaw('lower(to_address) = ?', [mb_strtolower($address)])
            ->where('kind', $kind)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->latest('id')
            ->first();
    }

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
        'PaymentReceiptNotification' => 'Payment receipt',
        'PaymentLinkNotification' => 'Payment link',
        'PaymentRefundedNotification' => 'Refund notice',
        'PaymentPartReceivedNotification' => 'Part payment notice',
        'BankAccountVerificationNotification' => 'Bank account confirmation',
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

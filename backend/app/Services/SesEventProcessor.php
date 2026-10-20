<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\User;
use App\Notifications\EmailBouncedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Turns what Amazon SES reports about a message into the delivery result on the email log (client, Joel, 2026-10-15 and the 2026-10-19
 * checklist: delivered or bounced). SES publishes events for a configuration set to an Amazon SNS topic, and the portal receives them
 * (SesWebhookController). A message is found by the id SES gave it, and then by the address, because one message can go to several.
 *
 * Staff are told when an email to a person bounced for good or was marked as spam, because that is when a customer silently gets
 * nothing (a mistyped address is the real case that started the email log).
 */
class SesEventProcessor
{
    /** SES event types, in both spellings it uses (eventType in event publishing, notificationType in feedback notifications). */
    private const STATUS_OF = [
        'Delivery' => EmailLog::DELIVERY_DELIVERED,
        'Bounce' => EmailLog::DELIVERY_BOUNCED,
        'Complaint' => EmailLog::DELIVERY_COMPLAINT,
        'DeliveryDelay' => EmailLog::DELIVERY_DELAYED,
        'Reject' => EmailLog::DELIVERY_REJECTED,
    ];

    /**
     * @param  array<string, mixed>  $event  the decoded SES event
     * @return int how many email log rows it changed
     */
    public function apply(array $event): int
    {
        $type = (string) ($event['eventType'] ?? $event['notificationType'] ?? '');
        $status = self::STATUS_OF[$type] ?? null;
        $messageId = (string) ($event['mail']['messageId'] ?? '');
        if ($status === null || $messageId === '') {
            // Send, Open, Click and the like are not delivery results.
            return 0;
        }

        $changed = 0;
        foreach ($this->recipientsOf($type, $event) as $recipient) {
            $rows = EmailLog::where('ses_message_id', $messageId)
                ->when($recipient['address'] !== null, fn ($q) => $q->whereRaw('lower(to_address) = ?', [mb_strtolower($recipient['address'])]))
                ->get();

            foreach ($rows as $row) {
                if ($row->recordDelivery($status, $recipient['at'], $recipient['detail'])) {
                    $changed++;
                    $this->tellStaff($row, $status);
                }
            }
        }

        return $changed;
    }

    /**
     * Who the event is about, when it happened and why.
     *
     * @return list<array{address: ?string, at: ?Carbon, detail: ?string}>
     */
    private function recipientsOf(string $type, array $event): array
    {
        $at = static function (?string $stamp): ?Carbon {
            try {
                return $stamp ? Carbon::parse($stamp) : null;
            } catch (Throwable) {
                return null;
            }
        };

        switch ($type) {
            case 'Delivery':
                $when = $at($event['delivery']['timestamp'] ?? null);

                return array_map(fn ($a) => ['address' => (string) $a, 'at' => $when, 'detail' => null], $event['delivery']['recipients'] ?? []);

            case 'Bounce':
                $b = $event['bounce'] ?? [];
                $when = $at($b['timestamp'] ?? null);
                $kind = trim(($b['bounceType'] ?? '').' '.($b['bounceSubType'] ?? ''));

                return array_map(fn ($r) => [
                    'address' => $r['emailAddress'] ?? null,
                    'at' => $when,
                    'detail' => trim($kind.(! empty($r['diagnosticCode']) ? ': '.$r['diagnosticCode'] : '')) ?: null,
                ], $b['bouncedRecipients'] ?? []);

            case 'Complaint':
                $c = $event['complaint'] ?? [];
                $when = $at($c['timestamp'] ?? null);
                $why = ! empty($c['complaintFeedbackType']) ? 'Marked as spam ('.$c['complaintFeedbackType'].')' : 'Marked as spam';

                return array_map(fn ($r) => ['address' => $r['emailAddress'] ?? null, 'at' => $when, 'detail' => $why], $c['complainedRecipients'] ?? []);

            case 'DeliveryDelay':
                $d = $event['deliveryDelay'] ?? [];
                $when = $at($d['timestamp'] ?? null);
                $why = trim('Held up: '.($d['delayType'] ?? 'unknown reason'));

                return array_map(fn ($r) => ['address' => $r['emailAddress'] ?? null, 'at' => $when, 'detail' => $why], $d['delayedRecipients'] ?? []);

            case 'Reject':
                // A rejected message never left SES, so it has no recipient list: it is about every address it was sent to.
                return [['address' => null, 'at' => null, 'detail' => 'Rejected by the email service: '.($event['reject']['reason'] ?? 'unknown reason')]];
        }

        return [];
    }

    /** A message to a person that bounced for good, or was marked as spam, is something staff should hear of. */
    private function tellStaff(EmailLog $row, string $status): void
    {
        $permanent = $status === EmailLog::DELIVERY_BOUNCED && str_starts_with((string) $row->delivery_detail, 'Permanent');
        if (! $permanent && $status !== EmailLog::DELIVERY_COMPLAINT) {
            return;
        }

        try {
            Notification::send(
                User::whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN])->get(),
                new EmailBouncedNotification($row),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}

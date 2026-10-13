<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Throwable;

/**
 * Keeps the email log (client, Joel, 2026-10-13): a row is written just before
 * a message is handed to the email service and settled when the service
 * answers. A send that throws is settled from the error handler (with the
 * service's own message) or, if nothing reported it, when the request ends.
 *
 * Bound as a singleton so the in-flight bookkeeping belongs to one request.
 * Logging must never get in the way of sending: every entry point swallows its
 * own errors, and the Sending listener never returns false (which would cancel
 * the email).
 */
class EmailLogger
{
    /** @var array<int, list<int>> log ids, keyed by the id of the message object being sent */
    private array $inFlight = [];

    public function sending(MessageSending $event): void
    {
        try {
            $addresses = array_map(fn (Address $a) => $a->getAddress(), $event->message->getTo());
            if (! $addresses) {
                return;
            }

            $kind = class_basename((string) ($event->data['__laravel_notification'] ?? $event->data['__laravel_mailable'] ?? ''));
            $subject = Str::limit((string) $event->message->getSubject(), 255, '');

            $ids = [];
            foreach ($addresses as $address) {
                $ids[] = EmailLog::create([
                    // Looked up by the address as typed, so a mistyped address still points at the customer it belongs to.
                    'user_id' => User::withTrashed()->where('email', $address)->value('id'),
                    'to_address' => Str::limit($address, 254, ''),
                    'subject' => $subject ?: null,
                    'kind' => $kind ?: null,
                    'status' => EmailLog::STATUS_SENDING,
                ])->id;
            }
            $this->inFlight[spl_object_id($event->message)] = $ids;

            // A send that throws and is never reported would stay "sending" forever.
            app()->terminating(fn () => $this->failRemaining('The email service did not confirm this message. See the server error log.'));
        } catch (Throwable $e) {
            $this->noteOwnFailure($e);
        }
    }

    public function sent(MessageSent $event): void
    {
        try {
            $key = spl_object_id($event->message);
            $ids = $this->inFlight[$key] ?? [];
            unset($this->inFlight[$key]);
            if (! $ids) {
                return;
            }

            EmailLog::whereKey($ids)->update([
                'status' => EmailLog::STATUS_SENT,
                'error' => null,
                'provider_message_id' => Str::limit((string) $event->sent->getMessageId(), 191, '') ?: null,
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->noteOwnFailure($e);
        }
    }

    /** The email service (or the connection to it) refused or failed: settle every message still in flight. */
    public function failed(Throwable $e): void
    {
        $this->failRemaining($e->getMessage());
    }

    public function failRemaining(string $reason): void
    {
        try {
            $ids = array_merge(...array_values($this->inFlight ?: [[]]));
            $this->inFlight = [];
            if (! $ids) {
                return;
            }

            EmailLog::whereKey($ids)->where('status', EmailLog::STATUS_SENDING)->update([
                'status' => EmailLog::STATUS_FAILED,
                'error' => Str::limit($reason, 1000, ''),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->noteOwnFailure($e);
        }
    }

    private function noteOwnFailure(Throwable $e): void
    {
        Log::warning('The email log could not be updated.', ['error' => $e->getMessage()]);
    }
}

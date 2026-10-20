<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Throwable;

/**
 * Keeps the email log (client, Joel, 2026-10-13): a row is written just before
 * a message is handed to the email service and settled when the service
 * answers. A send that throws and is reported is marked failed with the
 * service's own message. A send nobody heard back from is left as "not
 * confirmed" (never "failed": nothing proved it failed).
 *
 * The "sent" event cannot be matched to the "sending" one by object: the real
 * transports (SES and every other Symfony AbstractTransport) send a COPY of
 * the message, so the first release showed every real email as failed. A
 * message is matched by who it is for and its subject instead, oldest first,
 * which is also right for several messages in one request.
 *
 * Bound as a singleton so the in-flight bookkeeping belongs to one request.
 * Logging must never get in the way of sending: every entry point swallows its
 * own errors, and the Sending listener never returns false (which would cancel
 * the email).
 */
class EmailLogger
{
    /** @var list<array{ids: list<int>, to: list<string>, subject: string}> messages handed to the email service, oldest first */
    private array $inFlight = [];

    public function sending(MessageSending $event): void
    {
        try {
            $addresses = $this->addressesOf($event->message->getTo());
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
            $this->inFlight[] = ['ids' => $ids, 'to' => $this->normalized($addresses), 'subject' => $subject];

            // A send that is never answered would stay "sending" without a word about why.
            app()->terminating(fn () => $this->noteUnconfirmed());
        } catch (Throwable $e) {
            $this->noteOwnFailure($e);
        }
    }

    public function sent(MessageSent $event): void
    {
        try {
            $position = $this->positionOf($event);
            if ($position === null) {
                return;
            }
            $ids = $this->inFlight[$position]['ids'];
            array_splice($this->inFlight, $position, 1);

            EmailLog::whereKey($ids)->update([
                'status' => EmailLog::STATUS_SENT,
                'error' => null,
                'provider_message_id' => Str::limit((string) $event->sent->getMessageId(), 191, '') ?: null,
                // Amazon SES's own id for the message (the transport writes it on the message once SES has accepted it): how its delivery results find this row.
                'ses_message_id' => $this->sesMessageIdOf($event),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->noteOwnFailure($e);
        }
    }

    private function sesMessageIdOf(MessageSent $event): ?string
    {
        $original = $event->sent->getOriginalMessage();
        $header = $original instanceof Message ? $original->getHeaders()->get('X-SES-Message-ID') : null;
        $id = $header ? trim($header->getBodyAsString()) : '';

        return $id !== '' ? Str::limit($id, 191, '') : null;
    }

    /** The email service (or the connection to it) refused or failed: settle every message still in flight as failed. */
    public function failed(Throwable $e): void
    {
        try {
            $ids = $this->takeInFlightIds();
            if (! $ids) {
                return;
            }

            EmailLog::whereKey($ids)->where('status', EmailLog::STATUS_SENDING)->update([
                'status' => EmailLog::STATUS_FAILED,
                'error' => Str::limit($e->getMessage(), 1000, ''),
                'updated_at' => now(),
            ]);
        } catch (Throwable $inner) {
            $this->noteOwnFailure($inner);
        }
    }

    /** The request is ending and nobody heard back for these: say so, but do not call it failed. */
    public function noteUnconfirmed(): void
    {
        try {
            $ids = $this->takeInFlightIds();
            if (! $ids) {
                return;
            }

            EmailLog::whereKey($ids)->where('status', EmailLog::STATUS_SENDING)->update([
                'error' => 'No confirmation was received from the email service.',
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->noteOwnFailure($e);
        }
    }

    /** Which in-flight message this "sent" event belongs to: the first with the same recipients and subject. */
    private function positionOf(MessageSent $event): ?int
    {
        $to = $this->normalized($this->addressesOf($event->message->getTo()));
        $subject = Str::limit((string) $event->message->getSubject(), 255, '');

        foreach ($this->inFlight as $position => $entry) {
            if ($entry['to'] === $to && $entry['subject'] === $subject) {
                return $position;
            }
        }

        return null;
    }

    /** @param  array<int, Address>  $addresses
     * @return list<string> */
    private function addressesOf(array $addresses): array
    {
        return array_values(array_map(fn (Address $a) => $a->getAddress(), $addresses));
    }

    /** @param  list<string>  $addresses
     * @return list<string> */
    private function normalized(array $addresses): array
    {
        $lower = array_map('strtolower', $addresses);
        sort($lower);

        return $lower;
    }

    /** @return list<int> */
    private function takeInFlightIds(): array
    {
        $ids = [];
        foreach ($this->inFlight as $entry) {
            array_push($ids, ...$entry['ids']);
        }
        $this->inFlight = [];

        return $ids;
    }

    private function noteOwnFailure(Throwable $e): void
    {
        Log::warning('The email log could not be updated.', ['error' => $e->getMessage()]);
    }
}

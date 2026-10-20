<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Tells a customer where a bank account stands when it needs two small test deposits (client, Joel, 2026-10-19 checklist, section 2):
 * the deposits are on their way and where to confirm them, the account was confirmed and saved, or it could not be confirmed. The
 * email carries the same words as the screen, because the deposits take a day or two and the customer will have left the page.
 */
class BankAccountVerificationNotification extends Notification
{
    public const WAITING = 'waiting';

    public const VERIFIED = 'verified';

    public const FAILED = 'failed';

    /** @param  array<string, mixed>|null  $pending  what pendingBank() says, for the waiting email */
    public function __construct(
        private readonly string $state,
        private readonly int $leaseId,
        private readonly ?array $pending = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        [$title, $body] = match ($this->state) {
            self::WAITING => ['Confirm your bank account', 'Your bank is receiving two small deposits. When they show up, confirm the amounts to finish adding the account.'],
            self::VERIFIED => ['Your bank account is confirmed', 'Your bank account was confirmed and saved. You can use it to pay.'],
            default => ['Your bank account could not be confirmed', 'The bank account could not be confirmed. Please add it again, or use a different bank account or a card.'],
        };

        return ['type' => 'payment_method', 'title' => $title, 'body' => $body, 'action_url' => '/customer/leases/'.$this->leaseId.'/autopay'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);
        $mail = (new MailMessage)->subject($data['title'])->greeting("Hi {$notifiable->name},");

        if ($this->state === self::WAITING) {
            $by = ! empty($this->pending['arrives_by']) ? Carbon::parse($this->pending['arrives_by'])->format('F j, Y') : null;
            $code = ($this->pending['kind'] ?? 'amounts') === 'descriptor_code';
            $mail->line('To finish adding your bank account, we sent '.($code ? 'a small deposit' : 'two small deposits').' to it'.($by ? ", which should arrive by {$by}" : ', which usually arrive in 1 to 2 business days').'.')
                ->line($code
                    ? 'Look for the deposit on your statement. Its description starts with SM and has a six character code. Enter that code to confirm the account.'
                    : 'Look for the two deposits on your statement, each a few cents. Enter their amounts to confirm the account.');
            if (! empty($this->pending['verify_url'])) {
                $mail->action('Confirm my bank account', $this->pending['verify_url']);
            }

            return $mail->line('Nothing is charged to this account until it is confirmed.');
        }

        return $mail->line("**{$data['title']}**")->line($data['body']);
    }
}

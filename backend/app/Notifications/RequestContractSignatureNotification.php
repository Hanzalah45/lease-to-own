<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a guest-originated customer (no usable password yet) once their
 * application reaches "waiting on deposit" — carries the signed
 * sign-your-contract link (see ContractSigner). A customer who already has a
 * working login just signs from their own portal instead; this is only for
 * the guest case.
 */
class RequestContractSignatureNotification extends Notification
{
    public function __construct(private readonly string $signUrl) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'contract_signature_requested',
            'title' => 'Your lease agreement is ready to sign',
            'body' => 'Review and sign your Outdoor Fix lease agreement to continue.',
            'action_url' => '/sign-contract',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Sign your lease agreement')
            ->greeting("Hi {$notifiable->name},")
            ->line('Your Outdoor Fix lease agreement is ready to review and sign.')
            ->action('Review & sign', $this->signUrl)
            ->line('This link expires in 72 hours. If you weren\'t expecting this, you can ignore it.');
    }
}

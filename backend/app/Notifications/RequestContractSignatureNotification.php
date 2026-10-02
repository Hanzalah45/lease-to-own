<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a guest-originated customer (no usable password yet) once their
 * application reaches "waiting on deposit" — carries the signed link to the
 * contract preview page (see ContractSigner), which now walks them through
 * the whole consolidated onboarding journey from there (create account, sign,
 * AutoPay, payment — client, Joel, 2026-10-02). Previously paired with a
 * second, separate PaymentMethodsRequestedNotification email; that second
 * send was removed when this became the single entry point. A customer who
 * already has a working login just signs from their own portal instead; this
 * is only for the guest case.
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
            'title' => 'Your lease agreement is ready',
            'body' => 'Review your Prostart Leasing lease agreement, then create your account and sign to continue.',
            'action_url' => '/sign-contract',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Review & sign your lease agreement')
            ->greeting("Hi {$notifiable->name},")
            ->line('Your Prostart Leasing lease agreement is ready to review.')
            ->line('Create your account, then sign. It only takes a minute.')
            ->action('Get started', $this->signUrl)
            ->line('This link expires in 72 hours. If you weren\'t expecting this, you can ignore it.');
    }
}

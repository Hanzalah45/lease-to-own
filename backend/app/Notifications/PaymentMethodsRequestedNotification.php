<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a guest-originated customer (no usable password yet) once their
 * application reaches "waiting on deposit" — carries the signed AutoPay
 * setup link (see PaymentMethodSigner). A customer who already has a working
 * login just sets this up from their own portal instead; this is only for
 * the guest case. Mirrors RequestContractSignatureNotification exactly.
 */
class PaymentMethodsRequestedNotification extends Notification
{
    public function __construct(private readonly string $setupUrl) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_methods_requested',
            'title' => 'Set up AutoPay for your lease',
            'body' => 'Add a bank account and a backup card so AutoPay is ready to go.',
            'action_url' => '/setup-autopay',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Set up AutoPay for your lease')
            ->greeting("Hi {$notifiable->name},")
            ->line('Add a bank account and a backup card to your Prostart Leasing account so AutoPay is ready to go.')
            ->action('Set up AutoPay', $this->setupUrl)
            ->line('This link expires in 72 hours. If you weren\'t expecting this, you can ignore it.');
    }
}

<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the customer when an admin clicks "Request bank verification" — carries the signed Plaid-connect link (see BankVerificationSigner). */
class RequestBankVerificationNotification extends Notification
{
    public function __construct(private readonly string $verifyUrl) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'bank_verification_requested',
            'title' => 'Connect your bank to finish verification',
            'body' => 'Outdoor Fix is ready to verify your bank account. Connect it via Plaid to continue.',
            'action_url' => '/verify-bank',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Connect your bank')
            ->greeting("Hi {$notifiable->name},")
            ->line('Outdoor Fix needs to verify your bank account to continue processing your lease application.')
            ->action('Connect bank account', $this->verifyUrl)
            ->line('This link expires in 72 hours. If you weren\'t expecting this, you can ignore it.');
    }
}

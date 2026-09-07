<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a guest-originated customer's first payment is marked paid
 * (Admin\PaymentController::update()) — carries the signed link
 * (AccountSetupSigner) that lets them set a real password and log in to
 * their portal for the first time.
 */
class ActivateAccountNotification extends Notification
{
    public function __construct(private readonly string $setupUrl) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account_setup',
            'title' => 'Set up your account',
            'body' => 'Your first payment is in. Set a password to access your lease portal.',
            'action_url' => '/login',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Set up your account')
            ->greeting("Hi {$notifiable->name},")
            ->line('Your first payment has been received, thanks!')
            ->line('Set a password now to access your lease portal, make payments, and track your Early Purchase Option.')
            ->action('Set up your account', $this->setupUrl)
            ->line('This link expires in 14 days. If you weren\'t expecting this, you can ignore it.');
    }
}

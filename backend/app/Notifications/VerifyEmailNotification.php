<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent at registration (and on a resend request) with the signed link that activates the account. */
class VerifyEmailNotification extends Notification
{
    public function __construct(private readonly string $verifyUrl) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account',
            'title' => 'Verify your email to activate your account',
            'body' => 'Check your email for a link to activate your account before you can sign in.',
            'action_url' => '/login',
        ];
    }

    // Custom rather than the shared BuildsMailFromArray trait: the action link here
    // is the full signed verify URL (with query params), not an in-app portal path.
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your email')
            ->greeting("Hi {$notifiable->name},")
            ->line('Thanks for signing up! Please verify your email address to activate your account.')
            ->action('Verify email address', $this->verifyUrl)
            ->line('This link expires in 24 hours. If you didn\'t create an account, you can ignore this email.');
    }
}

<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Sent to the customer once their Plaid bank connection succeeds. */
class BankVerifiedNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'bank_verified',
            'title' => 'Bank account verified',
            'body' => 'Your bank connection was verified via Plaid — deposit history and pay frequency are on file.',
            'action_url' => '/customer/account',
        ];
    }
}

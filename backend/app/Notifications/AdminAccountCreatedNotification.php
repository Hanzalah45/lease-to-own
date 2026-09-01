<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** In-app companion to the welcome email — shows up in the new admin's own notification feed. */
class AdminAccountCreatedNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account',
            'title' => 'Your admin account is ready',
            'body' => 'Check your email for login credentials. You can change your password once you sign in.',
            'action_url' => '/admin/dashboard',
        ];
    }
}

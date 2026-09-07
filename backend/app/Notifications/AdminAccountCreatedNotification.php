<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * In-app companion to the welcome email — shows up in the new admin's own
 * notification feed. Database-only, deliberately: AdminUserController
 * already sends the real credentials via a dedicated AdminAccountCreatedMail;
 * a second "check your email" *email* referring to that first one would be
 * a confusing, redundant inbox message.
 */
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

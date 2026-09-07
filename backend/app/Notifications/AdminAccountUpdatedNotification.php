<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/** Sent to an admin when a super admin changes their permissions or account status. */
class AdminAccountUpdatedNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly string $summary) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account',
            'title' => 'Your account was updated',
            'body' => $this->summary,
            'action_url' => '/admin/dashboard',
        ];
    }
}

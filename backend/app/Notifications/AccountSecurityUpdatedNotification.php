<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/** Sent to a user when their own email or password changes — self-service, or an admin acting on a customer's behalf. */
class AccountSecurityUpdatedNotification extends Notification
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
            'title' => 'Security update',
            'body' => $this->summary,
            'action_url' => $notifiable->isStaff() ? '/admin/account' : '/customer/account',
        ];
    }
}

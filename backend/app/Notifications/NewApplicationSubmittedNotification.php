<?php

namespace App\Notifications;

use App\Models\Application;
use Illuminate\Notifications\Notification;

/** Sent to every admin/super admin with application-review access when a new lease application is submitted. */
class NewApplicationSubmittedNotification extends Notification
{
    public function __construct(private readonly Application $application) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $this->application->loadMissing('customer');

        return [
            'type' => 'application',
            'title' => "New lease application from {$this->application->customer->name}",
            'body' => "Application #{$this->application->id} is awaiting review",
            'action_url' => "/admin/applications/{$this->application->id}",
        ];
    }
}

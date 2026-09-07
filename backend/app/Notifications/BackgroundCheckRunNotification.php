<?php

namespace App\Notifications;

use App\Models\Application;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/** Sent to risk_assessment staff when an admin runs the "Run background check" action on an application. */
class BackgroundCheckRunNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly Application $application, private readonly string $backgroundCheckStatus) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->application->loadMissing('customer');

        return [
            'type' => 'background_check',
            'title' => "Background check run for {$this->application->customer->name}",
            'body' => 'Result: '.ucfirst($this->backgroundCheckStatus).", application #{$this->application->id}",
            'action_url' => "/admin/applications/{$this->application->id}",
        ];
    }
}

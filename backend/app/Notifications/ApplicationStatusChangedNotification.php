<?php

namespace App\Notifications;

use App\Models\Application;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/** Sent to the customer whenever an admin moves their application to a new status. */
class ApplicationStatusChangedNotification extends Notification
{
    use BuildsMailFromArray;

    private const LABELS = [
        Application::STATUS_WAITING_REVIEW => 'is waiting for review',
        Application::STATUS_NEEDS_INFO => 'needs more information',
        Application::STATUS_WAITING_APPROVAL => 'is ready for your approval call',
        Application::STATUS_IN_VERIFICATION => 'is in verification',
        Application::STATUS_WAITING_DEPOSIT => 'is waiting on your deposit',
        Application::STATUS_WAITING_DELIVERY => 'is waiting on delivery',
        Application::STATUS_FINISHED => 'is finished: your lease is active',
        Application::STATUS_DECLINED => 'was declined',
        Application::STATUS_WITHDRAWN => 'was withdrawn',
    ];

    public function __construct(private readonly Application $application) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $label = self::LABELS[$this->application->status] ?? "changed to {$this->application->status}";

        return [
            'type' => 'application',
            'title' => "Your application #{$this->application->id} {$label}",
            'body' => $this->application->status_notes ?? 'Tap to view the full details.',
            'action_url' => "/customer/applications",
        ];
    }
}

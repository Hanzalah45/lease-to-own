<?php

namespace App\Notifications;

use App\Models\Application;
use Illuminate\Notifications\Notification;

/** Sent to the customer whenever an admin moves their application to a new status. */
class ApplicationStatusChangedNotification extends Notification
{
    private const LABELS = [
        Application::STATUS_UNDER_REVIEW => 'is now under review',
        Application::STATUS_NEEDS_INFO => 'needs more information',
        Application::STATUS_APPROVED => 'was approved',
        Application::STATUS_COMPLETED => 'was completed',
        Application::STATUS_PROCESSED => 'payment is processing',
        Application::STATUS_FUNDED_PAID => 'is funded — your lease is active',
        Application::STATUS_DECLINED => 'was declined',
        Application::STATUS_WITHDRAWN => 'was withdrawn',
    ];

    public function __construct(private readonly Application $application) {}

    public function via(object $notifiable): array
    {
        return ['database'];
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

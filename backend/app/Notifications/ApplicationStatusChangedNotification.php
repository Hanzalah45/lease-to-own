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

        // A guest-originated customer's account is unusable until Phase 6's
        // account-setup link (see ApplicationCreationService) — sending them
        // a "View in portal" button before then would just dead-end at a
        // login they can't complete, so the portal link only appears once
        // there's a real account to log into.
        $hasUsableAccount = $notifiable->status !== 'pending';

        return [
            'type' => 'application',
            'title' => "Your application #{$this->application->id} {$label}",
            'body' => $this->application->status_notes ?? ($hasUsableAccount
                ? 'Tap to view the full details.'
                : "We'll email you again with next steps — no action needed from you right now."),
            'action_url' => $hasUsableAccount ? '/customer/applications' : null,
        ];
    }
}

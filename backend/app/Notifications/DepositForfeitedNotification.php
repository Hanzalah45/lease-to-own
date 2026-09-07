<?php

namespace App\Notifications;

use App\Models\Application;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/**
 * Sent to admins with application-review access when the daily
 * deposits:forfeit-expired-holds job cancels an application because the
 * customer didn't pick up within the 30-day deposit hold (client,
 * 2026-09-05). The customer's own copy is the existing, generic
 * ApplicationStatusChangedNotification (declined, with status_notes
 * explaining why) — this one exists so staff know it happened
 * automatically, not via a manual decline.
 */
class DepositForfeitedNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly Application $application) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->application->loadMissing('customer');

        return [
            'type' => 'deposit_forfeited',
            'title' => "Deposit forfeited: application #{$this->application->id}",
            'body' => "{$this->application->customer->name} didn't pick up within 30 days of signing — the deposit was forfeited and the application was declined.",
            'action_url' => "/admin/applications/{$this->application->id}",
        ];
    }
}

<?php

namespace App\Notifications;

use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/** Sent to the customer when their equipment is assigned (delivery scheduled) or released (returned/owned). */
class EquipmentStatusChangedNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly string $message) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'equipment',
            // Was the bare model name ("Worldlawn Zero-Turn 52") — gave no
            // indication anything happened when scanning a subject line or
            // the in-app feed. $message already says what changed.
            'title' => $this->message,
            'body' => $this->message,
            'action_url' => '/customer/equipment',
        ];
    }
}

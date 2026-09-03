<?php

namespace App\Notifications;

use App\Models\EquipmentUnit;
use Illuminate\Notifications\Notification;

/** Sent to the customer when their equipment is assigned (delivery scheduled) or released (returned/owned). */
class EquipmentStatusChangedNotification extends Notification
{
    public function __construct(private readonly EquipmentUnit $unit, private readonly string $message) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'equipment',
            'title' => $this->unit->model,
            'body' => $this->message,
            'action_url' => '/customer/equipment',
        ];
    }
}

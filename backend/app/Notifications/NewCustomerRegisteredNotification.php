<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/** Sent to every admin/super admin so a new self-registered customer shows up in "All Notifications". */
class NewCustomerRegisteredNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly User $customer) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account',
            'title' => "{$this->customer->name} signed up for a customer account",
            'body' => $this->customer->email,
            'action_url' => "/admin/customers/{$this->customer->id}",
        ];
    }
}

<?php

namespace App\Notifications;

use App\Models\LeaseAgreement;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/**
 * Tells payment staff that the system locked a saved payment method or froze a lease's automatic charging, and why (a hard decline, an open
 * chargeback), or that such a freeze was lifted. Staff only: the customer is not told that their method looks fraudulent.
 */
class PaymentSafetyNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(
        private readonly LeaseAgreement $lease,
        private readonly string $title,
        private readonly string $body,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->lease->loadMissing('customer', 'application');

        return [
            'type' => 'payment',
            'title' => "{$this->title}: {$this->lease->customer->name}",
            'body' => $this->body,
            'action_url' => $this->lease->application ? "/admin/applications/{$this->lease->application->id}" : '/admin/payments',
        ];
    }
}

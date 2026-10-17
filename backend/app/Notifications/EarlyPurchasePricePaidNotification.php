<?php

namespace App\Notifications;

use App\Models\LeaseAgreement;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/**
 * Staff-only alert that a customer has paid the whole early purchase price through extra payments (contract Section 19).
 * The sales tax is due when the early purchase option is exercised, and the equipment still has to be released as owned
 * by the customer, so a person finishes it: AutoPay was already paused so nothing more is charged meanwhile.
 */
class EarlyPurchasePricePaidNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly LeaseAgreement $lease) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->lease->loadMissing('customer', 'application');

        return [
            'type' => 'payment',
            'title' => "Early purchase price paid in full: {$this->lease->customer->name}",
            'body' => 'The early purchase price is paid through extra payments. AutoPay was paused. Collect the sales tax due on the early purchase, release the equipment as owned by the customer, and remove the monthly payments still scheduled.',
            'action_url' => $this->lease->application ? "/admin/applications/{$this->lease->application->id}" : '/admin/payments',
        ];
    }
}

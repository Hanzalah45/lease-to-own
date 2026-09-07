<?php

namespace App\Notifications;

use App\Models\LeaseAgreement;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/** Sent to the customer when their monthly lease renewal is processed, with the current payment and EPO amount. */
class LeaseRenewalProcessedNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly LeaseAgreement $lease, private readonly float $epoToday) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $payment = number_format($this->lease->totalMonthlyPayment(), 2);
        $epo = number_format($this->epoToday, 2);

        return [
            'type' => 'account',
            'title' => 'Your lease renewed for another month',
            'body' => "Your monthly payment is \${$payment}. Today's early purchase option price is \${$epo}.",
            'action_url' => "/customer/leases/{$this->lease->id}",
        ];
    }
}

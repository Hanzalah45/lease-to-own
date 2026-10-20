<?php

namespace App\Notifications;

use App\Models\AutopayAgreement;
use App\Models\LeaseAgreement;
use App\Notifications\Concerns\BuildsMailFromArray;
use App\Services\BillingClock;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A customer turned on AutoPay with the tick box. The customer gets an email that repeats what they agreed to (a copy for their records),
 * staff get an alert in the portal.
 */
class AutopayAgreedNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(
        private readonly LeaseAgreement $lease,
        private readonly AutopayAgreement $agreement,
    ) {}

    public function via(object $notifiable): array
    {
        return $notifiable->isStaff() ? ['database'] : ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = $this->buildBaseMail($notifiable);

        // The customer keeps a copy of exactly what was agreed to, with the terms that were shown next to it.
        $mail->line('What you agreed to:');
        $mail->line('"'.$this->agreement->wording.'"');
        $mail->line('Agreed on '.$this->agreement->agreed_at->setTimezone(BillingClock::TIMEZONE)->format('F j, Y \a\t g:i A').' (Central time).');

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        $this->lease->loadMissing('customer', 'application');
        $when = $this->agreement->agreed_at->setTimezone(BillingClock::TIMEZONE)->format('M j, Y g:i A');

        if ($notifiable->isStaff()) {
            return [
                'type' => 'payment',
                'title' => "AutoPay turned on: {$this->lease->customer->name}",
                'body' => "The customer agreed to AutoPay with the tick box on {$when} (Central time). AutoPay charges the first payment that falls due on or after that day.",
                'action_url' => $this->lease->application ? "/admin/applications/{$this->lease->application->id}" : '/admin/payments',
            ];
        }

        return [
            'type' => 'payment',
            'title' => 'AutoPay is on for your lease',
            'body' => "On {$when} (Central time) you agreed to pay your monthly lease payments automatically. AutoPay charges the first payment that falls due on or after that day. You can see your saved payment methods any time on your Payments page.",
            'action_url' => '/customer/payments',
        ];
    }
}

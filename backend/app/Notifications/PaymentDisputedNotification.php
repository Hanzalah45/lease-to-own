<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Models\PaymentDispute;
use App\Notifications\Concerns\BuildsMailFromArray;
use App\Services\BillingClock;
use Illuminate\Notifications\Notification;

/**
 * A customer disputed a payment with their bank (a chargeback). Staff only: the evidence has to be sent in Stripe by a set day, so this
 * goes out by email as well as in the portal.
 */
class PaymentDisputedNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(
        private readonly Payment $payment,
        private readonly PaymentDispute $dispute,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->payment->loadMissing('leaseAgreement.customer', 'leaseAgreement.application');
        $amount = number_format((float) $this->dispute->amount, 2);
        $dueDate = $this->payment->due_date?->toDateString() ?? 'unknown date';
        $type = str_replace('_', ' ', $this->payment->type);
        $respond = $this->dispute->evidence_due_by
            ? ' Send your evidence in Stripe by '.$this->dispute->evidence_due_by->setTimezone(BillingClock::TIMEZONE)->format('F j, Y').'.'
            : ' Respond in Stripe.';
        $application = $this->payment->leaseAgreement->application;

        return [
            'type' => 'payment',
            'title' => 'Chargeback opened: '.$this->payment->leaseAgreement->customer->name,
            'body' => "The customer disputed the \${$amount} {$type} payment due {$dueDate} with their bank. Stripe has taken the money back for now.{$respond}",
            'action_url' => $application ? "/admin/applications/{$application->id}" : '/admin/payments',
        ];
    }
}

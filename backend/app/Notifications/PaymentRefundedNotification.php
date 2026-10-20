<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/**
 * Money went back for a payment: a refund staff made, one made in the Stripe dashboard, or a chargeback that was lost. The customer
 * hears about a refund (a chargeback is their own bank's doing, so only staff are told), staff get an alert in the portal.
 */
class PaymentRefundedNotification extends Notification
{
    use BuildsMailFromArray;

    /** @param bool $mail false when the customer was already emailed a receipt for this refund: the portal notice is still made. */
    public function __construct(
        private readonly Payment $payment,
        private readonly PaymentRefund $refund,
        private readonly bool $mail = true,
    ) {}

    public function via(object $notifiable): array
    {
        return $notifiable->isStaff() || ! $this->mail ? ['database'] : ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->payment->loadMissing('leaseAgreement.customer', 'leaseAgreement.application');
        $refunded = number_format((float) $this->refund->amount, 2);
        $paid = number_format($this->payment->totalCharged(), 2);
        $dueDate = $this->payment->due_date?->toDateString() ?? 'unknown date';
        $type = str_replace('_', ' ', $this->payment->type);

        if ($notifiable->isStaff()) {
            $application = $this->payment->leaseAgreement->application;
            $chargeback = $this->refund->reason === PaymentRefund::REASON_CHARGEBACK;

            return [
                'type' => 'payment',
                'title' => ($chargeback ? 'Chargeback lost: ' : 'Refund issued: ').$this->payment->leaseAgreement->customer->name,
                'body' => $chargeback
                    ? "A chargeback of \${$refunded} on the \${$paid} {$type} payment due {$dueDate} was decided against us. Stripe has taken the money back."
                    : "\${$refunded} was refunded on the \${$paid} {$type} payment due {$dueDate} ({$this->refund->reasonLabel()}).",
                'action_url' => $application ? "/admin/applications/{$application->id}" : '/admin/payments',
            ];
        }

        return [
            'type' => 'payment',
            'title' => 'Your refund is on its way',
            'body' => "A refund of \${$refunded} for your \${$paid} {$type} payment due {$dueDate} has been issued. It goes back to the card or bank account you paid with, and can take 5 to 10 business days to show on your statement.",
            'action_url' => '/customer/payments',
        ];
    }
}

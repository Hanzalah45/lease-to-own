<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Models\PaymentPart;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/**
 * A piece of a payment came in and the payment is not fully paid yet (client, Joel, 2026-10-19 checklist, section 7). It says what was
 * received and what is still due, so the customer can see exactly what remains. When the last piece comes in, the ordinary payment
 * message (PaymentStatusChangedNotification) is sent instead.
 */
class PaymentPartReceivedNotification extends Notification
{
    use BuildsMailFromArray;

    private const TYPE_LABEL = [
        Payment::TYPE_RENTAL => 'monthly payment',
        Payment::TYPE_LATE_FEE => 'late fee',
        Payment::TYPE_DEPOSIT => 'security deposit',
        Payment::TYPE_PICKUP_BALANCE => 'tracking fee and first month',
    ];

    private const METHOD_LABEL = ['ach' => 'bank account', 'card' => 'card', 'cash' => 'cash', 'check' => 'check', 'other' => 'another method'];

    /** @param bool $mail false when the customer was already emailed a receipt for this piece: the portal notice is still made. */
    public function __construct(
        private readonly Payment $payment,
        private readonly PaymentPart $part,
        private readonly bool $mail = true,
    ) {}

    public function via(object $notifiable): array
    {
        return $notifiable->isStaff() || ! $this->mail ? ['database'] : ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->payment->loadMissing('leaseAgreement.customer', 'leaseAgreement.application');
        $received = number_format($this->part->totalCharged(), 2);
        $left = number_format($this->payment->fresh()->remainingAmount(), 2);
        $label = self::TYPE_LABEL[$this->payment->type] ?? 'payment';
        $method = self::METHOD_LABEL[$this->part->method] ?? $this->part->method;
        $due = $this->payment->due_date?->toDateString() ?? 'unknown date';

        if ($notifiable->isStaff()) {
            $application = $this->payment->leaseAgreement->application;

            return [
                'type' => 'payment',
                'title' => 'Part payment received: '.$this->payment->leaseAgreement->customer->name,
                'body' => "\${$received} by {$method} was received toward the {$label} due {$due}. \${$left} is still due.",
                'action_url' => $application ? "/admin/applications/{$application->id}" : '/admin/payments',
            ];
        }

        return [
            'type' => 'payment',
            'title' => 'We received part of your payment',
            'body' => "We received \${$received} by {$method} toward your {$label} due {$due}. \${$left} is still due.",
            'action_url' => '/customer/payments',
        ];
    }
}

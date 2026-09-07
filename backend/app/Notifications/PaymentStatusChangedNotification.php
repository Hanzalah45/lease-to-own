<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/**
 * Sent to the customer whenever one of their payments is recorded (paid,
 * failed, or refunded). Also sent to payment_tracking staff, for both a
 * successful payment and a failure.
 */
class PaymentStatusChangedNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->payment->loadMissing('leaseAgreement.customer');
        $amount = number_format((float) $this->payment->amount, 2);
        $dueDate = $this->payment->due_date?->toDateString() ?? 'unknown date';

        if ($notifiable->isStaff()) {
            $staffLabel = match ($this->payment->status) {
                Payment::STATUS_PAID => 'was recorded as paid',
                Payment::STATUS_REFUNDED => 'was refunded',
                default => 'was marked failed',
            };

            return [
                'type' => 'payment',
                'title' => "Payment {$this->payment->status}: {$this->payment->leaseAgreement->customer->name}",
                'body' => "The \${$amount} payment due {$dueDate} {$staffLabel}.",
                'action_url' => '/admin/payments',
            ];
        }

        $label = match ($this->payment->status) {
            Payment::STATUS_PAID => 'recorded as paid',
            Payment::STATUS_FAILED => 'marked failed',
            Payment::STATUS_REFUNDED => 'refunded',
            default => $this->payment->status,
        };

        return [
            'type' => 'payment',
            'title' => 'Payment update',
            'body' => "Your \${$amount} payment due {$dueDate} was {$label}.",
            'action_url' => '/customer/payments',
        ];
    }
}

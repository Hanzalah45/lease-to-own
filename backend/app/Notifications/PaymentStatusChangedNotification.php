<?php

namespace App\Notifications;

use App\Models\Payment;
use Illuminate\Notifications\Notification;

/**
 * Sent to the customer whenever one of their payments is recorded (paid,
 * failed, or refunded). Also sent to payment_tracking staff, but only for a
 * failure — the customer-facing red flag is otherwise silent until someone
 * happens to open the application.
 */
class PaymentStatusChangedNotification extends Notification
{
    public function __construct(private readonly Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $this->payment->loadMissing('leaseAgreement.customer');
        $amount = number_format((float) $this->payment->amount, 2);
        $dueDate = $this->payment->due_date?->toDateString() ?? 'unknown date';

        if ($notifiable->isStaff()) {
            return [
                'type' => 'payment',
                'title' => "Payment failed — {$this->payment->leaseAgreement->customer->name}",
                'body' => "The \${$amount} payment due {$dueDate} was marked failed.",
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

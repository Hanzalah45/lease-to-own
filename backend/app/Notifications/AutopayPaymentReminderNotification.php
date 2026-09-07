<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/**
 * Sent to autopay customers ~1 week before a payment is due (client,
 * 2026-09-04 — confirmed email/in-app, not SMS: this app has no SMS provider).
 * Reuses the payment_reminder_emails preference toggle, same as
 * PaymentStatusChangedNotification.
 */
class AutopayPaymentReminderNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $amount = number_format((float) $this->payment->amount, 2);
        $dueDate = $this->payment->due_date?->toFormattedDateString() ?? 'soon';

        return [
            'type' => 'payment',
            'title' => 'Upcoming autopay payment',
            'body' => "Your \${$amount} autopay payment is due {$dueDate}.",
            'action_url' => '/customer/payments',
        ];
    }
}

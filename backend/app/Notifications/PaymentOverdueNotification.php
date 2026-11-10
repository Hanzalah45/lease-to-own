<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Services\LeaseEngine;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a customer the day after a monthly payment's due date that it has not been received (client, Joel, 2026-11-02: a customer
 * whose payment is missed, not only one whose autopay fails, should hear of it at once). Sent by payments:send-overdue-notices.
 */
class PaymentOverdueNotification extends Notification
{
    public function __construct(private readonly Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** What is still to be paid, and what it costs to leave it: the figures the email and the portal notice both use. */
    private function figures(): array
    {
        $this->payment->loadMissing('leaseAgreement');

        return [
            'amount' => number_format($this->payment->remainingAmount(), 2),
            'due' => $this->payment->due_date?->toFormattedDateString() ?? 'its due date',
            'lateFee' => number_format(LeaseEngine::lateFeeFor($this->payment), 2),
            'lateFrom' => $this->payment->due_date?->copy()->addDays(LeaseEngine::LATE_FEE_GRACE_DAYS)->toFormattedDateString() ?? '10 days after the due date',
        ];
    }

    public function toArray(object $notifiable): array
    {
        $f = $this->figures();

        return [
            'type' => 'payment',
            'title' => 'Your payment has not been received',
            'body' => "Your \${$f['amount']} payment that was due {$f['due']} has not been received. Please pay it, or update your bank account or card, on your Payments page.",
            'action_url' => '/customer/payments',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $f = $this->figures();

        return (new MailMessage)
            ->subject('Your payment has not been received')
            ->greeting("Hi {$notifiable->name},")
            ->line("**We have not received your \${$f['amount']} payment that was due {$f['due']}.**")
            ->line('If you have already paid, thank you, and you can ignore this email.')
            ->line('If not, please make the payment on your Payments page, or update your bank account or card there, as soon as you can.')
            ->line("What happens next: if the payment is still unpaid 10 days after the due date, on {$f['lateFrom']}, a late fee of \${$f['lateFee']} is added.")
            ->action('Open my payments', rtrim((string) config('app.frontend_url'), '/').'/customer/payments');
    }
}

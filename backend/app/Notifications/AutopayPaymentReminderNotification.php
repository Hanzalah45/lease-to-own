<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\BuildsMailFromArray;
use App\Services\CardPricing;
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
        $dueDate = $this->payment->due_date?->toFormattedDateString() ?? 'soon';

        // What will actually be charged depends on which method AutoPay uses
        // first (dual pricing, client 2026-10-05): the bank price, or the
        // card price (bank price + the card fee).
        $this->payment->loadMissing('leaseAgreement');
        $bankCents = CardPricing::toCents((float) $this->payment->amount);
        $method = $this->payment->leaseAgreement?->autopayChargeablePaymentMethod();
        $feeCents = $method ? CardPricing::feeCentsFor($bankCents, $method['type']) : 0;
        $amount = number_format(($bankCents + $feeCents) / 100, 2);

        $body = "Your \${$amount} autopay payment is due {$dueDate}.";
        if ($feeCents > 0) {
            $body .= sprintf(
                ' It will be charged to your card and includes a $%s card fee. Paying from your bank account instead would be $%s.',
                number_format($feeCents / 100, 2),
                number_format($bankCents / 100, 2),
            );
        }

        return [
            'type' => 'payment',
            'title' => 'Upcoming autopay payment',
            'body' => $body,
            'action_url' => '/customer/payments',
        ];
    }
}

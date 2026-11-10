<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Notifications\Concerns\BuildsMailFromArray;
use App\Services\LeaseEngine;
use App\Services\PaymentFailureReason;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the customer whenever one of their payments is recorded (paid,
 * failed, or refunded). Also sent to payment_tracking staff, for both a
 * successful payment and a failure.
 *
 * A payment that did not go through is a message of its own to the customer (client, Joel, 2026-11-02: his insurance company
 * emailed him the moment his autopay failed): it says so plainly, why when that is known, what to do, and what happens next.
 */
class PaymentStatusChangedNotification extends Notification
{
    use BuildsMailFromArray;

    /** @param bool $mail false when the customer was already emailed a receipt for this payment: the portal notice is still made. */
    public function __construct(private readonly Payment $payment, private readonly bool $mail = true) {}

    public function via(object $notifiable): array
    {
        return ! $this->mail && ! $notifiable->isStaff() ? ['database'] : ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->payment->loadMissing('leaseAgreement.customer');
        // The total actually charged: a card payment carries the card fee on
        // top of the bank price (dual pricing, client 2026-10-05).
        $amount = number_format($this->payment->totalCharged(), 2);
        $fee = (float) $this->payment->card_fee_amount;
        $feeNote = $fee > 0 ? sprintf(' (includes a $%s card fee)', number_format($fee, 2)) : '';
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
                'body' => "The \${$amount} payment{$feeNote} due {$dueDate} {$staffLabel}.",
                'action_url' => '/admin/payments',
            ];
        }

        if ($this->payment->status === Payment::STATUS_FAILED) {
            return [
                'type' => 'payment',
                'title' => 'Your payment did not go through',
                'body' => "Your \${$amount} payment{$feeNote} due {$dueDate} did not go through. Please update your bank account or card on your Payments page, or make the payment there.",
                'action_url' => '/customer/payments',
            ];
        }

        $label = match ($this->payment->status) {
            Payment::STATUS_PAID => 'recorded as paid',
            Payment::STATUS_REFUNDED => 'refunded',
            default => $this->payment->status,
        };

        return [
            'type' => 'payment',
            'title' => 'Payment update',
            'body' => "Your \${$amount} payment{$feeNote} due {$dueDate} was {$label}.",
            'action_url' => '/customer/payments',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($notifiable->isStaff() || $this->payment->status !== Payment::STATUS_FAILED) {
            return $this->buildBaseMail($notifiable);
        }

        $this->payment->loadMissing('leaseAgreement.customer');
        $amount = number_format($this->payment->totalCharged(), 2);
        $due = $this->payment->due_date?->toFormattedDateString() ?? 'its due date';

        // Why, when the charge itself said so: the last attempt that failed on this payment.
        $code = $this->payment->attempts()->where('status', PaymentAttempt::STATUS_FAILED)->latest('id')->value('failure_code');
        $reason = PaymentFailureReason::describe($code);

        $mail = (new MailMessage)
            ->subject('Your payment did not go through')
            ->greeting("Hi {$notifiable->name},")
            ->line("**Your \${$amount} payment due {$due} did not go through.**");

        if ($reason) {
            $mail->line("Reason: {$reason}");
        }

        $mail->line('What to do: please update your bank account or card on your Payments page, or make the payment there, as soon as you can.');

        // What happens next: the late fee the contract sets, and the day it starts (see ChargeLateFees).
        if ($this->payment->type === Payment::TYPE_RENTAL && $this->payment->due_date) {
            $lateFee = number_format(LeaseEngine::lateFeeFor($this->payment), 2);
            $from = $this->payment->due_date->copy()->addDays(LeaseEngine::LATE_FEE_GRACE_DAYS)->toFormattedDateString();
            $mail->line("What happens next: if the payment is still unpaid 10 days after the due date, on {$from}, a late fee of \${$lateFee} is added.");
        }

        return $mail->action('Open my payments', rtrim((string) config('app.frontend_url'), '/').'/customer/payments');
    }
}

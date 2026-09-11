<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/**
 * Sent when the daily payments:charge-late-fees job assesses a late fee on
 * a rental payment still pending 10+ days after its due date (contract
 * Section 8, "Late Fee"). Also sent to payment_tracking staff.
 */
class LateFeeChargedNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly Payment $lateFee) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->lateFee->loadMissing('leaseAgreement.customer', 'lateFeeForPayment');
        $amount = number_format((float) $this->lateFee->amount, 2);
        $originalDueDate = $this->lateFee->lateFeeForPayment?->due_date?->toDateString() ?? 'unknown date';

        if ($notifiable->isStaff()) {
            return [
                'type' => 'payment',
                'title' => "Late fee charged: {$this->lateFee->leaseAgreement->customer->name}",
                'body' => "A \${$amount} late fee was charged because the payment due {$originalDueDate} is still unpaid.",
                'action_url' => '/admin/payments',
            ];
        }

        return [
            'type' => 'payment',
            'title' => 'Late fee charged',
            'body' => "A \${$amount} late fee was added to your account because your payment due {$originalDueDate} is more than 10 days late.",
            'action_url' => '/customer/payments',
        ];
    }
}

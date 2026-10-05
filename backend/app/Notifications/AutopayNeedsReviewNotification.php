<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/**
 * Staff-only alert that an automatic charge reached a state a person has to
 * look at (a second collection on an already-paid payment, or a charge whose
 * outcome Stripe never confirmed) — see AutopayOutcome and AutopayCharger.
 */
class AutopayNeedsReviewNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly Payment $payment, private readonly string $reason) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->payment->loadMissing('leaseAgreement.customer');
        $amount = number_format((float) $this->payment->amount, 2);

        return [
            'type' => 'payment',
            'title' => "AutoPay needs review: {$this->payment->leaseAgreement->customer->name}",
            'body' => "Payment #{$this->payment->id} (\${$amount}, due {$this->payment->due_date?->toDateString()}): {$this->reason}",
            'action_url' => '/admin/payments',
        ];
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Notifications\PaymentOverdueNotification;
use App\Services\BillingClock;
use Illuminate\Console\Command;

/**
 * Emails a customer the day after a monthly payment's due date that it has not been received (client, Joel, 2026-11-02: "sending the
 * second email the day after the due date works for me"). It is for a payment still pending, such as a customer who is not on
 * AutoPay, or whose AutoPay had no usable method; a payment an AutoPay charge could not collect is already told "your payment did not
 * go through" when it is marked failed, so it is not told twice.
 *
 * Only leases already picked up have a real schedule to miss, and a payment with a charge on its way (a bank debit takes days) is not
 * overdue. A payment is told once: overdue_notice_sent_at marks it, so running the job again the same day sends nothing.
 */
class SendPaymentOverdueNotices extends Command
{
    protected $signature = 'payments:send-overdue-notices';

    protected $description = 'Tell customers the day after a monthly payment was due that it has not been received';

    public function handle(): int
    {
        $yesterday = BillingClock::today()->subDay()->toDateString();

        $payments = Payment::with('leaseAgreement.customer')
            ->where('type', Payment::TYPE_RENTAL)
            ->where('status', Payment::STATUS_PENDING)
            ->whereNull('overdue_notice_sent_at')
            ->whereNull('stripe_payment_intent_id')
            ->whereDoesntHave('attempts', fn ($q) => $q->whereIn('status', [PaymentAttempt::STATUS_INITIATED, PaymentAttempt::STATUS_PROCESSING]))
            ->whereDate('due_date', '<=', $yesterday)
            // Yesterday's payments, and the day before in case a run was missed; an older one is never told by this job.
            ->whereDate('due_date', '>=', BillingClock::today()->subDays(2)->toDateString())
            ->whereHas('leaseAgreement.application', fn ($q) => $q->where('status', Application::STATUS_FINISHED))
            ->get();

        $sent = 0;
        foreach ($payments as $payment) {
            if ($payment->remainingAmount() < 0.01) {
                continue;
            }
            $customer = $payment->leaseAgreement->customer;

            // The payment is marked first: a mail hiccup on one customer must not make tomorrow's run tell everyone again.
            $payment->forceFill(['overdue_notice_sent_at' => now()])->save();

            // Sent even to a customer who switched off payment reminder emails: it is crucial communication to keep the lease in good
            // standing (client, Joel, 2026-11-09).
            try {
                $customer->notify(new PaymentOverdueNotification($payment));
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("Told {$sent} customer(s) about a payment that was not received, out of {$payments->count()} overdue payment(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Notifications\AutopayPaymentReminderNotification;
use Illuminate\Console\Command;

/**
 * Autopay reminder (client, 2026-09-04): a payment due in ~1 week gets a
 * heads-up email/in-app notification. Runs daily, so each pending payment
 * matches "due in exactly 7 days" on only one day — no duplicate sends.
 * Email/in-app only, not real SMS (client confirmed, 2026-09-04 — this app
 * has no SMS provider).
 */
class SendAutopayPaymentReminders extends Command
{
    protected $signature = 'payments:send-autopay-reminders';

    protected $description = 'Notify autopay customers about a payment due in about a week';

    public function handle(): int
    {
        $dueDate = now()->addDays(7)->toDateString();

        $payments = Payment::where('status', Payment::STATUS_PENDING)
            ->whereDate('due_date', $dueDate)
            ->whereHas('leaseAgreement', fn ($query) => $query->where('autopay_enabled', true))
            ->with('leaseAgreement.customer.customerProfile')
            ->get();

        $sent = 0;
        foreach ($payments as $payment) {
            $customer = $payment->leaseAgreement->customer;
            if ($customer->customerProfile?->payment_reminder_emails ?? true) {
                $customer->notify(new AutopayPaymentReminderNotification($payment));
                $sent++;
            }
        }

        $this->info("Sent {$sent} autopay reminder(s) out of {$payments->count()} due-in-7-days payment(s).");

        return self::SUCCESS;
    }
}

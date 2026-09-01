<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\RiskRedFlag;
use App\Services\RiskRedFlagger;
use Illuminate\Console\Command;

/**
 * Milestone 3's continuous risk monitoring for the one payment signal the
 * app can check without a live processor: a payment still pending after
 * its due date. Skips any payment that already has an unresolved
 * missed_payment flag, so this is safe to run daily without re-flagging
 * the same unpaid installment every time.
 */
class FlagMissedPayments extends Command
{
    protected $signature = 'risk:flag-missed-payments';

    protected $description = 'Flag payments that are still pending past their due date as a missed_payment risk red flag';

    public function handle(): int
    {
        $overdue = Payment::with('leaseAgreement')
            ->where('status', Payment::STATUS_PENDING)
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereDoesntHave('riskRedFlags', fn ($q) => $q->where('type', RiskRedFlag::TYPE_MISSED_PAYMENT)->where('resolved', false))
            ->get();

        foreach ($overdue as $payment) {
            RiskRedFlagger::flag(
                $payment->leaseAgreement->customer_id,
                RiskRedFlag::TYPE_MISSED_PAYMENT,
                sprintf(
                    'Payment of $%s due %s is still unpaid.',
                    number_format((float) $payment->amount, 2),
                    $payment->due_date?->toDateString() ?? 'unknown date',
                ),
                $payment,
            );
        }

        $this->info("Flagged {$overdue->count()} overdue payment(s).");

        return self::SUCCESS;
    }
}

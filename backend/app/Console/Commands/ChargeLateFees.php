<?php

namespace App\Console\Commands;

use App\Models\AdminPermission;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\LateFeeChargedNotification;
use App\Services\LeaseEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Client, 2026-09-10: automatically charge the contract's Late Fee (Section
 * 8) once a Rental Payment sits pending 10+ days past its due date — 10% of
 * the payment, floored at $5, capped at $30. Charged as its own pending
 * Payment row (type late_fee) rather than an instant bank debit, since no
 * live payment processor is wired up yet (see PaymentController::update());
 * staff record its collection the same way as any other payment. The
 * lateFeeCharge relation guards against charging the same overdue payment
 * twice, matching the clause's "once per billing cycle... will not recur."
 */
class ChargeLateFees extends Command
{
    protected $signature = 'payments:charge-late-fees';

    protected $description = 'Charge a late fee on rental payments still pending 10+ days after their due date';

    public function handle(): int
    {
        $cutoff = now()->subDays(LeaseEngine::LATE_FEE_GRACE_DAYS)->toDateString();

        $overdue = Payment::with('leaseAgreement.customer.customerProfile')
            ->where('type', Payment::TYPE_RENTAL)
            ->where('status', Payment::STATUS_PENDING)
            ->whereDate('due_date', '<=', $cutoff)
            ->whereDoesntHave('lateFeeCharge')
            ->get();

        $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($inner) {
                        $inner->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::PAYMENT_TRACKING));
                    });
            })->get();

        foreach ($overdue as $payment) {
            $lateFee = Payment::create([
                'lease_agreement_id' => $payment->lease_agreement_id,
                'type' => Payment::TYPE_LATE_FEE,
                'late_fee_for_payment_id' => $payment->id,
                'amount' => LeaseEngine::lateFeeFor($payment),
                'due_date' => now()->toDateString(),
                'status' => Payment::STATUS_PENDING,
            ]);

            // The fee is already charged above — a mail transport hiccup (e.g. a
            // rate limit) on one customer's notification must not abort the loop
            // and leave every other overdue customer's fee uncharged for the day.
            $customer = $payment->leaseAgreement->customer;
            if ($customer->customerProfile?->payment_reminder_emails ?? true) {
                try {
                    $customer->notify(new LateFeeChargedNotification($lateFee));
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            try {
                Notification::send($recipients, new LateFeeChargedNotification($lateFee));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("Charged {$overdue->count()} late fee(s).");

        return self::SUCCESS;
    }
}

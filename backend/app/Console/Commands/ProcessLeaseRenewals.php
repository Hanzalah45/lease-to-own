<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Notifications\LeaseRenewalProcessedNotification;
use App\Services\BillingClock;
use App\Services\LeaseEngine;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * The plan's "fixed renewal due date each month; automatic renewal if the
 * customer keeps the equipment without notice" — advances renewal_date by a
 * month and notifies the customer with the current payment/EPO amount.
 * Owned leases are done renewing; nothing else in the app currently reads
 * renewal_date, so this is the first thing that actually moves it forward.
 */
class ProcessLeaseRenewals extends Command
{
    protected $signature = 'lease:process-renewals';

    protected $description = 'Advance the renewal date for leases due today and notify the customer with the current payment/EPO amount';

    public function handle(): int
    {
        $today = BillingClock::today();

        // Only leases already picked up renew (before pickup there is no term
        // running), and the date advances from the existing renewal_date, not
        // from today, so it stays anchored to the billing cycle (the 1st or
        // 15th) instead of drifting by however late this command ran.
        $due = LeaseAgreement::whereDate('renewal_date', '<=', $today->toDateString())
            ->where('ownership_status', '!=', LeaseAgreement::OWNERSHIP_OWNED)
            ->whereHas('application', fn ($q) => $q->where('status', Application::STATUS_FINISHED))
            ->with('customer')
            ->get();

        foreach ($due as $lease) {
            $next = Carbon::parse($lease->renewal_date)->startOfDay();
            do {
                $next = $next->copy()->addMonthNoOverflow();
            } while ($next->lte($today));

            $lease->update(['renewal_date' => $next->toDateString()]);

            $lease->customer?->notify(new LeaseRenewalProcessedNotification($lease, LeaseEngine::epoToday($lease)));
        }

        $this->info("Renewed {$due->count()} lease(s).");

        return self::SUCCESS;
    }
}

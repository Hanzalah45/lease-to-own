<?php

namespace App\Console\Commands;

use App\Models\LeaseAgreement;
use App\Notifications\LeaseRenewalProcessedNotification;
use App\Services\LeaseEngine;
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
        $due = LeaseAgreement::whereDate('renewal_date', '<=', now()->toDateString())
            ->where('ownership_status', '!=', LeaseAgreement::OWNERSHIP_OWNED)
            ->with('customer')
            ->get();

        foreach ($due as $lease) {
            $lease->update(['renewal_date' => now()->addMonthNoOverflow()->toDateString()]);

            $lease->customer?->notify(new LeaseRenewalProcessedNotification($lease, LeaseEngine::epoToday($lease)));
        }

        $this->info("Renewed {$due->count()} lease(s).");

        return self::SUCCESS;
    }
}

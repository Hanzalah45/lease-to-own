<?php

namespace App\Console\Commands;

use App\Services\AutopayRevocationService;
use Illuminate\Console\Command;

/**
 * Switches AutoPay off for every customer whose request to turn it off has reached its day (three business days after they asked, as the
 * AutoPay authorization says). Runs in the small hours Central time, ahead of the first AutoPay charges of the day, which also refuse to
 * charge a lease whose request has taken effect. See AutopayRevocationService.
 */
class ApplyAutopayRevocations extends Command
{
    protected $signature = 'autopay:apply-revocations';

    protected $description = 'Switch AutoPay off where a customer\'s three business day notice has run out';

    public function handle(AutopayRevocationService $revocations): int
    {
        $this->info('AutoPay switched off on '.$revocations->applyDue().' lease(s).');

        return self::SUCCESS;
    }
}

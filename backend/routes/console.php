<?php

use App\Services\BillingClock;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Payment-date commands run on the client's (Texas) morning rather than 00:00
// UTC, which is the previous evening there: a payment due on the 1st must not
// be flagged missed, or a reminder sent, at 7pm on the 30th. See BillingClock.
Schedule::command('risk:flag-missed-payments')->dailyAt('08:00')->timezone(BillingClock::TIMEZONE);
Schedule::command('lease:process-renewals')->dailyAt('08:00')->timezone(BillingClock::TIMEZONE);
Schedule::command('payments:send-autopay-reminders')->dailyAt('08:00')->timezone(BillingClock::TIMEZONE);
Schedule::command('payments:charge-late-fees')->dailyAt('08:00')->timezone(BillingClock::TIMEZONE);
Schedule::command('deposits:forfeit-expired-holds')->daily();

// Charges due monthly payments (a no-op until AUTOPAY_CHARGING_ENABLED is on).
// Every 15 minutes so a charge with an unknown outcome is replayed promptly and
// a failed bank debit falls back to the card the same morning; first attempts
// hold until 9am Central inside the command. See AutopayCharger.
Schedule::command('payments:charge-autopay')->everyFifteenMinutes()->withoutOverlapping(10);

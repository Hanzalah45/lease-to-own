<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * The client operates in Texas, but the app (and the production server) run
 * on UTC: a pickup at 8pm Central on the 14th is already the 15th in UTC,
 * which would flip a billing date. Every "what day is it for the customer"
 * decision (pickup date, due-today checks, reminders, late fees) goes through
 * here instead of calling now() directly.
 */
class BillingClock
{
    public const TIMEZONE = 'America/Chicago';

    /** The current moment in the client's time zone. */
    public static function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    /** Today's calendar date in the client's time zone, as a midnight Carbon. */
    public static function today(): Carbon
    {
        return self::now()->startOfDay();
    }

    /** Today's calendar date in the client's time zone, as Y-m-d. */
    public static function todayDate(): string
    {
        return self::today()->toDateString();
    }
}

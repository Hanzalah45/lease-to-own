<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Business days, as the bank counts them: Monday to Friday, except the days the Federal Reserve is closed (the AutoPay authorization asks
 * for notice "at least three (3) business days" ahead, and the debits run on the banking system). A holiday that falls on a Sunday is
 * observed on the Monday; one that falls on a Saturday is not moved, as the Federal Reserve's own calendar has it.
 */
class BusinessDays
{
    /** The calendar date n business days after $from, as a midnight Carbon in the same time zone. */
    public static function add(Carbon $from, int $days): Carbon
    {
        $date = $from->copy()->startOfDay();
        $left = $days;
        while ($left > 0) {
            $date->addDay();
            if (self::isBusinessDay($date)) {
                $left--;
            }
        }

        return $date;
    }

    public static function isBusinessDay(Carbon $date): bool
    {
        return ! $date->isWeekend() && ! in_array($date->toDateString(), self::holidays($date->year), true);
    }

    /**
     * The Federal Reserve holidays of a year, as Y-m-d, with Sunday holidays moved to the Monday.
     *
     * @return list<string>
     */
    public static function holidays(int $year): array
    {
        $nth = fn (int $month, int $weekday, int $n) => Carbon::create($year, $month, 1)->nthOfMonth($n, $weekday);
        $last = fn (int $month, int $weekday) => Carbon::create($year, $month, 1)->lastOfMonth($weekday);

        $days = [
            Carbon::create($year, 1, 1),            // New Year's Day
            $nth(1, Carbon::MONDAY, 3),             // Martin Luther King Jr. Day
            $nth(2, Carbon::MONDAY, 3),             // Washington's Birthday
            $last(5, Carbon::MONDAY),               // Memorial Day
            Carbon::create($year, 6, 19),           // Juneteenth
            Carbon::create($year, 7, 4),            // Independence Day
            $nth(9, Carbon::MONDAY, 1),             // Labor Day
            $nth(10, Carbon::MONDAY, 2),            // Columbus Day
            Carbon::create($year, 11, 11),          // Veterans Day
            $nth(11, Carbon::THURSDAY, 4),          // Thanksgiving
            Carbon::create($year, 12, 25),          // Christmas
        ];

        return array_map(
            fn (Carbon $day) => ($day->isSunday() ? $day->copy()->addDay() : $day)->toDateString(),
            $days,
        );
    }
}

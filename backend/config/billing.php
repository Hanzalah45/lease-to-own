<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Card price (dual pricing)
    |--------------------------------------------------------------------------
    |
    | Client (Joel), 2026-10-05: every amount paid by card costs this much
    | more than the same amount paid by bank/ACH. Bank pays exactly the stored
    | lease price ("bank price"); card pays bank price + this fee. Expressed
    | in basis points (300 = 3.00%) so the arithmetic stays in integer cents.
    | See App\Services\CardPricing.
    |
    */

    'card_fee_bps' => (int) env('BILLING_CARD_FEE_BPS', 300),

    /*
    |--------------------------------------------------------------------------
    | Automatic monthly charging (AutoPay)
    |--------------------------------------------------------------------------
    |
    | Kill switch for `payments:charge-autopay`, the daily job that charges
    | each due monthly payment through Stripe. OFF by default: it moves real
    | money, so it is switched on deliberately per environment (after a
    | `--dry-run` review), never by merely deploying the code.
    |
    */

    'autopay_charging_enabled' => (bool) env('AUTOPAY_CHARGING_ENABLED', false),

    // First attempts for a day's due payments wait until this hour in the
    // client's time zone (America/Chicago); retries and fallbacks do not.
    'autopay_first_attempt_hour' => (int) env('AUTOPAY_FIRST_ATTEMPT_HOUR', 9),

    // An ACH debit still "processing" after this many days is looked up on
    // Stripe directly instead of waiting forever for a webhook that never came.
    'autopay_reconcile_after_days' => (int) env('AUTOPAY_RECONCILE_AFTER_DAYS', 3),

];

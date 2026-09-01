<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\RiskProfile;
use App\Models\RiskRedFlag;

/**
 * Milestone 3's continuous post-approval monitoring: writes to the
 * risk_red_flags table from the real signals the app already has (a
 * payment marked failed, a payment gone overdue, a bank reconnection to a
 * different account). unreachable_customer, suspicious_behavior,
 * undisclosed_move, and gps_anomaly have no such signal yet — nothing in
 * the app currently detects them, so they're left for whenever that
 * detection is built (gps_anomaly is Phase 2).
 */
class RiskRedFlagger
{
    public static function flag(int $customerId, string $type, ?string $description = null, ?Payment $payment = null): RiskRedFlag
    {
        $riskProfile = RiskProfile::firstOrCreate(['customer_id' => $customerId]);

        return $riskProfile->redFlags()->create([
            'payment_id' => $payment?->id,
            'type' => $type,
            'description' => $description,
            'flagged_at' => now(),
        ]);
    }
}

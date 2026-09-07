<?php

namespace App\Services;

use App\Models\AdminPermission;
use App\Models\Payment;
use App\Models\RiskProfile;
use App\Models\RiskRedFlag;
use App\Models\User;
use App\Notifications\RedFlagDetectedNotification;
use Illuminate\Support\Facades\Notification;

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

        $redFlag = $riskProfile->redFlags()->create([
            'payment_id' => $payment?->id,
            'type' => $type,
            'description' => $description,
            'flagged_at' => now(),
        ]);

        $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($inner) {
                        $inner->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::RISK_ASSESSMENT));
                    });
            })->get();
        Notification::send($recipients, new RedFlagDetectedNotification($redFlag));

        return $redFlag;
    }
}

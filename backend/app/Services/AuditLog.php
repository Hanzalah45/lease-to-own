<?php

namespace App\Services;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Writes the payment audit trail (client, Joel, 2026-10-19 checklist, section 9: who performed the action, timestamp, action type,
 * payment method, IP/device). Called wherever money or a payment method is touched. The person, the address and the device are taken
 * from the request that is running; with no request (a Stripe event, the scheduler) the actor is the system.
 *
 * Writing the trail must never get in the way of the action it records, so a failure here is reported and swallowed.
 */
class AuditLog
{
    /**
     * @param  array{payment?: ?Payment, lease?: ?LeaseAgreement, method?: ?string, amount?: float|string|null, details?: array<string, mixed>, actor?: ?User, system?: bool}  $context
     */
    public static function record(string $action, string $summary, array $context = []): ?PaymentAuditLog
    {
        try {
            $payment = $context['payment'] ?? null;
            $lease = $context['lease'] ?? ($payment?->leaseAgreement);
            $actor = ! empty($context['system']) ? null : ($context['actor'] ?? Auth::user());

            $fromScreen = ! app()->runningInConsole() || app()->runningUnitTests();
            $request = $fromScreen ? request() : null;

            return PaymentAuditLog::create([
                'user_id' => $actor?->id,
                'actor_type' => $actor ? ($actor->isStaff() ? 'staff' : 'customer') : 'system',
                'actor_name' => $actor ? Str::limit($actor->name, 120, '') : null,
                'action' => $action,
                'lease_agreement_id' => $lease?->id ?? $payment?->lease_agreement_id,
                'payment_id' => $payment?->id,
                'method' => $context['method'] ?? $payment?->method,
                'amount' => $context['amount'] ?? null,
                'summary' => Str::limit($summary, 400, ''),
                'details' => $context['details'] ?? null,
                // Only an action made from a screen has an address and a device.
                'ip_address' => $actor && $request ? $request->ip() : null,
                'user_agent' => $actor && $request ? Str::limit((string) $request->userAgent(), 300, '') : null,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}

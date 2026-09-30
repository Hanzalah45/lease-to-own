<?php

namespace App\Services;

use App\Models\LeaseAgreement;
use App\Models\User;

/**
 * Signs and validates the "add your payment methods" link a guest-originated
 * customer needs to reach the AutoPay setup step — same gap ContractSigner
 * solves (no usable password until first payment, which happens after this
 * step), so it mirrors it exactly, with its own "payment_method|" prefix so
 * a link of one kind can never be replayed as the other.
 */
class PaymentMethodSigner
{
    private const TTL_HOURS = 72;

    public static function urlFor(User $customer, LeaseAgreement $lease): string
    {
        $hash = sha1($customer->email);
        $expires = now()->addHours(self::TTL_HOURS)->timestamp;
        $signature = self::sign($customer->id, $lease->id, $hash, $expires);

        return sprintf(
            '%s/setup-autopay?id=%d&lease=%d&hash=%s&expires=%d&signature=%s',
            rtrim((string) config('app.frontend_url'), '/'),
            $customer->id,
            $lease->id,
            $hash,
            $expires,
            $signature,
        );
    }

    public static function isValid(int $id, int $leaseId, string $hash, int $expires, string $signature): bool
    {
        if ($expires < now()->timestamp) {
            return false;
        }

        return hash_equals(self::sign($id, $leaseId, $hash, $expires), $signature);
    }

    private static function sign(int $id, int $leaseId, string $hash, int $expires): string
    {
        return hash_hmac('sha256', "payment_method|{$id}|{$leaseId}|{$hash}|{$expires}", (string) config('app.key'));
    }
}

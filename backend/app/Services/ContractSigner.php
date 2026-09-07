<?php

namespace App\Services;

use App\Models\LeaseAgreement;
use App\Models\User;

/**
 * Signs and validates the "sign your contract" link a guest-originated
 * customer needs to reach the e-signature step — a real, previously-hidden
 * gap: Customer\ContractController::store() sits behind auth:sanctum, but a
 * guest customer's account has no usable password until their first payment
 * (ApplicationCreationService::createGuestApplication / Phase 6 pickup
 * activation), which happens AFTER the contract is supposed to be signed in
 * the confirmed flow ("approval -> signed contract -> deposit -> pickup").
 * Mirrors BankVerificationSigner's HMAC scheme, with its own "contract|"
 * prefix (and the lease id folded in) so a link of one kind can never be
 * replayed as the other, or against a different lease.
 */
class ContractSigner
{
    private const TTL_HOURS = 72;

    public static function urlFor(User $customer, LeaseAgreement $lease): string
    {
        $hash = sha1($customer->email);
        $expires = now()->addHours(self::TTL_HOURS)->timestamp;
        $signature = self::sign($customer->id, $lease->id, $hash, $expires);

        return sprintf(
            '%s/sign-contract?id=%d&lease=%d&hash=%s&expires=%d&signature=%s',
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
        return hash_hmac('sha256', "contract|{$id}|{$leaseId}|{$hash}|{$expires}", (string) config('app.key'));
    }
}

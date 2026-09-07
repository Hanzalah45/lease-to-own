<?php

namespace App\Services;

use App\Models\User;

/**
 * Signs and validates the "connect your bank" link an admin sends via the
 * "Request bank verification" action (Admin\RiskProfileController). Mirrors
 * EmailVerificationSigner's HMAC scheme exactly, but deliberately a separate
 * class/signature namespace (the "bank|" prefix below) so a link of one kind
 * can never be replayed as the other — this one exists because a
 * guest-originated customer's shadow account has no usable password yet
 * (see ApplicationCreationService::createGuestApplication), so they can't
 * reach the authenticated Plaid endpoints Customer\PlaidController exposes.
 */
class BankVerificationSigner
{
    private const TTL_HOURS = 72;

    public static function urlFor(User $user): string
    {
        $hash = sha1($user->email);
        $expires = now()->addHours(self::TTL_HOURS)->timestamp;
        $signature = self::sign($user->id, $hash, $expires);

        return sprintf(
            '%s/verify-bank?id=%d&hash=%s&expires=%d&signature=%s',
            rtrim((string) config('app.frontend_url'), '/'),
            $user->id,
            $hash,
            $expires,
            $signature,
        );
    }

    public static function isValid(int $id, string $hash, int $expires, string $signature): bool
    {
        if ($expires < now()->timestamp) {
            return false;
        }

        return hash_equals(self::sign($id, $hash, $expires), $signature);
    }

    private static function sign(int $id, string $hash, int $expires): string
    {
        return hash_hmac('sha256', "bank|{$id}|{$hash}|{$expires}", (string) config('app.key'));
    }
}

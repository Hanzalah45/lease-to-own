<?php

namespace App\Services;

use App\Models\User;

/**
 * Signs and validates the "set up your account" link sent when a
 * guest-originated customer's first payment is marked paid (client,
 * 2026-09-04): their shadow account (see
 * ApplicationCreationService::createGuestApplication) has a random unusable
 * password, so this is how they set a real one and log in for the first
 * time — mirrors EmailVerificationSigner/BankVerificationSigner's HMAC
 * scheme, with its own domain-separating prefix so none of the three link
 * types can be replayed as another.
 */
class AccountSetupSigner
{
    private const TTL_HOURS = 24 * 14;

    public static function urlFor(User $user): string
    {
        $hash = sha1($user->email);
        $expires = now()->addHours(self::TTL_HOURS)->timestamp;
        $signature = self::sign($user->id, $hash, $expires);

        return sprintf(
            '%s/account-setup?id=%d&hash=%s&expires=%d&signature=%s',
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
        return hash_hmac('sha256', "account-setup|{$id}|{$hash}|{$expires}", (string) config('app.key'));
    }
}

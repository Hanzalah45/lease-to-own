<?php

namespace App\Services;

use App\Models\User;

/**
 * Signs and validates the "verify your email" link sent at registration.
 *
 * Deliberately not Laravel's built-in signed-route helpers: those bind the
 * signature to a specific backend route URL being revisited, but the link
 * we email points at a frontend page (which then posts the params back to
 * the API) — so this signs the same {id, hash, expires} triple with an
 * HMAC instead, independent of which URL carries it.
 */
class EmailVerificationSigner
{
    private const TTL_HOURS = 24;

    public static function urlFor(User $user): string
    {
        $hash = sha1($user->email);
        $expires = now()->addHours(self::TTL_HOURS)->timestamp;
        $signature = self::sign($user->id, $hash, $expires);

        return sprintf(
            '%s/verify-email?id=%d&hash=%s&expires=%d&signature=%s',
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
        return hash_hmac('sha256', "{$id}|{$hash}|{$expires}", (string) config('app.key'));
    }
}

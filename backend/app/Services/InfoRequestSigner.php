<?php

namespace App\Services;

use App\Models\Application;
use App\Models\User;

/**
 * Signs and validates the "respond to this request" link a guest-originated
 * customer needs to answer a "needs info" ask — a real gap found 2026-09-16:
 * Customer\ApplicationController::respondToInfoRequest() sits behind
 * auth:sanctum, but a guest customer's account has no usable password until
 * their first payment (ApplicationCreationService::createGuestApplication /
 * Phase 6 pickup activation), which can easily happen well after an admin
 * needs to ask them something. Mirrors ContractSigner/BankVerificationSigner's
 * HMAC scheme, with its own "info-request|" prefix so a link of one kind can
 * never be replayed as another, or against a different application.
 */
class InfoRequestSigner
{
    private const TTL_HOURS = 24 * 14;

    public static function urlFor(User $customer, Application $application): string
    {
        $hash = sha1($customer->email);
        $expires = now()->addHours(self::TTL_HOURS)->timestamp;
        $signature = self::sign($customer->id, $application->id, $hash, $expires);

        return sprintf(
            '%s/respond-info-request?id=%d&application=%d&hash=%s&expires=%d&signature=%s',
            rtrim((string) config('app.frontend_url'), '/'),
            $customer->id,
            $application->id,
            $hash,
            $expires,
            $signature,
        );
    }

    public static function isValid(int $id, int $applicationId, string $hash, int $expires, string $signature): bool
    {
        if ($expires < now()->timestamp) {
            return false;
        }

        return hash_equals(self::sign($id, $applicationId, $hash, $expires), $signature);
    }

    private static function sign(int $id, int $applicationId, string $hash, int $expires): string
    {
        return hash_hmac('sha256', "info-request|{$id}|{$applicationId}|{$hash}|{$expires}", (string) config('app.key'));
    }
}

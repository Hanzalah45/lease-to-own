<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Rejects an email whose domain does not exist, so a typo like "gmail.comt"
 * is caught when the address is typed instead of silently sending every email
 * to nowhere (client, Joel, 2026-10-12: a customer's signing links went to
 * "...@gmail.comt" and nothing ever arrived). Only the domain is checked, so a
 * real domain with a mistyped name before the @ still passes; that is
 * unavoidable without sending a test email.
 *
 * Needs the network, so it is switched by config('app.validate_email_dns'):
 * on in production, off for local work and tests.
 */
class EmailDomainExists implements ValidationRule
{
    /** A domain that certainly exists, used to tell "this domain is missing" from "DNS lookups are failing". */
    private const CANARY_DOMAIN = 'google.com';

    /** Replaces the DNS lookup `fn (string $name, string $type): bool`; tests use it so they need no network. */
    public static ?\Closure $lookup = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! config('app.validate_email_dns') || ! is_string($value) || ! str_contains($value, '@')) {
            return; // an unusable value is the format rule's job to report
        }

        $domain = strtolower(substr(strrchr($value, '@'), 1));
        // A non-ASCII domain would need punycode conversion; the format rule
        // already decides whether such an address is acceptable.
        if ($domain === '' || ! preg_match('/^[\x20-\x7e]+$/', $domain)) {
            return;
        }

        // A domain that resolved once keeps resolving for a day: a busy provider like gmail.com is not
        // looked up on every save, and a resolver hiccup cannot reject an address that was just fine.
        $cacheKey = 'email-domain-resolves:'.$domain;
        if (Cache::get($cacheKey)) {
            return;
        }

        if (self::domainResolves($domain)) {
            Cache::put($cacheKey, true, now()->addDay());

            return;
        }

        // A failed lookup says the domain does not exist only while DNS itself is answering. The server
        // cannot tell a missing domain from a resolver that timed out, so if a domain that surely exists
        // also fails, the lookup is what is broken and the address is let through.
        if (! self::domainResolves(self::CANARY_DOMAIN)) {
            Log::warning('The email domain check was skipped because DNS lookups are failing.', ['domain' => $domain]);

            return;
        }

        $fail('The part after the @ in this email address does not exist. Please check it for typos.');
    }

    /** A domain can receive mail if it has a mail server, or (RFC 5321) just an address record. */
    public static function domainResolves(string $domain): bool
    {
        $lookup = self::$lookup ?? 'checkdnsrr';
        $name = rtrim($domain, '.').'.';
        foreach (['MX', 'A', 'AAAA'] as $type) {
            if ($lookup($name, $type)) {
                return true;
            }
        }

        return false;
    }
}

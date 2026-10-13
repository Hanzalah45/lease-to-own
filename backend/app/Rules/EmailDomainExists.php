<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

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

        if (! self::domainResolves($domain)) {
            $fail('The part after the @ in this email address does not exist. Please check it for typos.');
        }
    }

    /** A domain can receive mail if it has a mail server, or (RFC 5321) just an address record. */
    public static function domainResolves(string $domain): bool
    {
        $name = rtrim($domain, '.').'.';
        foreach (['MX', 'A', 'AAAA'] as $type) {
            if (checkdnsrr($name, $type)) {
                return true;
            }
        }

        return false;
    }
}

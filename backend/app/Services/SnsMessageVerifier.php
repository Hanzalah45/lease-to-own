<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks that a message really came from Amazon SNS before anything in it is believed (the same job Stripe's signature check does for
 * Stripe). SNS signs each message with a certificate it publishes; the message names where that certificate is, so the address is only
 * trusted when it is an https address on Amazon's own SNS host. The signature is then checked over the fields SNS lists, in the order
 * its documentation gives, with the signature version the message states (1 is SHA1, 2 is SHA256).
 */
class SnsMessageVerifier
{
    /** The fields that are signed, by message type, in the order they are put together. */
    private const SIGNED_FIELDS = [
        'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
        'SubscriptionConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
    ];

    /** An address that is Amazon SNS's own: https, on sns.<region>.amazonaws.com (or its China domain). */
    public static function isSnsHost(string $url): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? '') === 'https'
            && isset($parts['host'])
            && preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', $parts['host']) === 1
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']);
    }

    /** @param  array<string, mixed>  $message  the decoded SNS message */
    public function isValid(array $message): bool
    {
        $type = $message['Type'] ?? null;
        $fields = self::SIGNED_FIELDS[$type] ?? null;
        $certUrl = $message['SigningCertURL'] ?? $message['SigningCertUrl'] ?? null;
        $signature = base64_decode((string) ($message['Signature'] ?? ''), true);

        if (! $fields || ! is_string($certUrl) || ! self::isSnsHost($certUrl) || $signature === false || $signature === '') {
            return false;
        }

        $algorithm = match ((string) ($message['SignatureVersion'] ?? '')) {
            '1' => OPENSSL_ALGO_SHA1,
            '2' => OPENSSL_ALGO_SHA256,
            default => null,
        };
        $certificate = $algorithm === null ? null : $this->certificate($certUrl);
        if ($certificate === null) {
            return false;
        }

        $text = '';
        foreach ($fields as $field) {
            // Subject is only part of the text when the message has one.
            if ($field === 'Subject' && ! isset($message['Subject'])) {
                continue;
            }
            if (! isset($message[$field]) || ! is_string($message[$field])) {
                return false;
            }
            $text .= $field."\n".$message[$field]."\n";
        }

        $key = openssl_pkey_get_public($certificate);

        return $key !== false && openssl_verify($text, $signature, $key, $algorithm) === 1;
    }

    /** The certificate SNS signs with, fetched once and kept for a day. */
    private function certificate(string $url): ?string
    {
        return Cache::remember('sns-cert-'.sha1($url), now()->addDay(), function () use ($url) {
            try {
                $response = Http::timeout(10)->get($url);

                return $response->successful() && str_contains($response->body(), 'BEGIN CERTIFICATE') ? $response->body() : null;
            } catch (Throwable $e) {
                report($e);

                return null;
            }
        });
    }
}

<?php

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * Stripe's PHP SDK talks to the network through its own cURL client
 * (Stripe\HttpClient\CurlClient), set globally via
 * Stripe\ApiRequestor::setHttpClient() — not Laravel's Http facade, so
 * Http::fake() can't intercept it (see RiskVerificationActionsTest for the
 * Plaid equivalent, which DOES use Http::fake() since Plaid's client goes
 * through Laravel's Http facade). This fake implements the same interface
 * Stripe's real client does, queued per-test with canned responses.
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{0: string, 1: int, 2: array}|\Throwable> */
    private array $queue = [];

    /** @var array<int, array{method: string, url: string, params: array, headers: array}> */
    public array $requests = [];

    /** @param  array<string, mixed>  $body */
    public function queue(array $body, int $status = 200): static
    {
        $this->queue[] = [json_encode($body), $status, []];

        return $this;
    }

    /** Makes the next request throw instead of returning a response (e.g. a network timeout). */
    public function queueException(\Throwable $exception): static
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params, 'headers' => $headers];

        $next = array_shift($this->queue) ?? ['{}', 200, []];
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** The Idempotency-Key header sent on the Nth recorded request (0-based), if any. */
    public function idempotencyKey(int $index): ?string
    {
        foreach ($this->requests[$index]['headers'] ?? [] as $header) {
            if (is_string($header) && stripos($header, 'Idempotency-Key:') === 0) {
                return trim(substr($header, strlen('Idempotency-Key:')));
            }
        }

        return null;
    }
}

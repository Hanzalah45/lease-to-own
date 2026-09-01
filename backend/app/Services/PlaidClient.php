<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around Plaid's REST API (https://plaid.com/docs/api/).
 * No SDK dependency — Plaid's API is plain JSON, and Laravel's HTTP client
 * already covers everything we need for Link token creation, exchanging a
 * public token, and reading account/identity data for bank verification.
 */
class PlaidClient
{
    private string $baseUrl;

    public function __construct(
        private readonly string $clientId,
        private readonly string $secret,
        string $env = 'sandbox',
    ) {
        $this->baseUrl = match ($env) {
            'production' => 'https://production.plaid.com',
            'development' => 'https://development.plaid.com',
            default => 'https://sandbox.plaid.com',
        };
    }

    /**
     * Create a Link token for the given customer so the frontend can launch
     * Plaid Link. https://plaid.com/docs/api/link/#linktokencreate
     */
    public function createLinkToken(int $userId, string $userName): string
    {
        $response = $this->post('/link/token/create', [
            'user' => ['client_user_id' => (string) $userId],
            'client_name' => 'Outdoor Fix',
            'products' => ['auth', 'transactions'],
            'country_codes' => ['US'],
            'language' => 'en',
        ]);

        return $response['link_token'];
    }

    /**
     * Exchange the public token Plaid Link returns on success for a
     * long-lived access token + item ID to store against the customer.
     * https://plaid.com/docs/api/items/#itempublic_tokenexchange
     */
    public function exchangePublicToken(string $publicToken): array
    {
        $response = $this->post('/item/public_token/exchange', [
            'public_token' => $publicToken,
        ]);

        return [
            'access_token' => $response['access_token'],
            'item_id' => $response['item_id'],
        ];
    }

    /**
     * Fetch the linked accounts for a stored access token — used to confirm
     * the connection is live and show basic account info for verification.
     * https://plaid.com/docs/api/accounts/#accountsget
     */
    public function getAccounts(string $accessToken): array
    {
        $response = $this->post('/accounts/get', [
            'access_token' => $accessToken,
        ]);

        return $response['accounts'];
    }

    private function post(string $path, array $payload): array
    {
        $response = Http::asJson()->post($this->baseUrl.$path, array_merge([
            'client_id' => $this->clientId,
            'secret' => $this->secret,
        ], $payload));

        if ($response->failed()) {
            $message = $response->json('error_message') ?? 'Plaid request failed.';
            throw new RuntimeException($message);
        }

        return $response->json();
    }
}

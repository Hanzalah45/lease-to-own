<?php

namespace App\Services;

use App\Models\QuickbooksConnection;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The QuickBooks Online accounting API for the one connected company.
 *
 * Tokens: an access token lasts an hour, and every refresh can hand back a NEW refresh token that makes the old one worthless,
 * so the latest of both is written back every time, and a refresh runs under a lock so two processes (the scheduler and a
 * person pressing a button) never refresh with the same old token.
 */
class QuickbooksClient
{
    /** Intuit retired minor versions below 75 in 2025; the newest is always sent. */
    private const MINOR_VERSION = 75;

    private const TIMEOUT_SECONDS = 20;

    /** The connected company's row, or an auth failure when there is none. */
    public function connection(): QuickbooksConnection
    {
        $connection = QuickbooksConnection::first();
        if (! $connection) {
            throw new QuickbooksException('QuickBooks is not connected.', authFailed: true);
        }

        return $connection;
    }

    /**
     * One call to the accounting API. A rejected token is refreshed once and the call repeated.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $connection = $this->connection();
            $token = $this->accessToken($connection, force: $attempt === 1);

            $url = rtrim(config('services.quickbooks.api_base'), '/')."/v3/company/{$connection->realm_id}/{$path}"
                .'?'.http_build_query(['minorversion' => self::MINOR_VERSION] + $query);

            try {
                $response = $this->http($token)->send($method, $url, $json === null ? [] : ['json' => $json]);
            } catch (ConnectionException $e) {
                throw $this->unreachable($e);
            }

            if ($response->status() === 401 && $attempt === 0) {
                continue;
            }

            return $this->decode($response);
        }

        throw new QuickbooksException('QuickBooks rejected the connection. Reconnect QuickBooks on the Settings page.', authFailed: true);
    }

    /**
     * Runs a QuickBooks query and returns the rows of the entity it asked for.
     *
     * @return list<array<string, mixed>>
     */
    public function query(string $entity, string $sql): array
    {
        $body = $this->request('GET', 'query', ['query' => $sql]);

        return $body['QueryResponse'][$entity] ?? [];
    }

    /**
     * Creates an entity. The request id makes QuickBooks answer a repeat of the same request with the original result instead
     * of creating a second copy.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> the created entity
     */
    public function create(string $entity, array $body, string $requestId): array
    {
        $response = $this->request('POST', strtolower($entity), ['requestid' => $requestId], $body);

        return $response[$entity] ?? [];
    }

    /**
     * Every active account of the company, by name.
     *
     * @return list<array{id: string, name: string, type: string, subtype: ?string}>
     */
    public function accounts(): array
    {
        $accounts = [];
        $start = 1;
        do {
            $page = $this->query('Account', "select * from Account where Active = true startposition {$start} maxresults 1000");
            foreach ($page as $row) {
                $accounts[] = [
                    'id' => (string) $row['Id'],
                    'name' => (string) ($row['FullyQualifiedName'] ?? $row['Name']),
                    'type' => (string) ($row['AccountType'] ?? ''),
                    'subtype' => $row['AccountSubType'] ?? null,
                ];
            }
            $start += 1000;
        } while (count($page) === 1000);

        usort($accounts, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $accounts;
    }

    /** The connection dropped or timed out. The detail (which carries the company's URL) goes to the log, not to the screen. */
    private function unreachable(ConnectionException $e): QuickbooksException
    {
        Log::warning('QuickBooks could not be reached.', ['error' => $e->getMessage()]);

        return new QuickbooksException('QuickBooks could not be reached (the connection dropped or timed out).', transient: true);
    }

    private function http(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(self::TIMEOUT_SECONDS);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $status = $response->status();
        if ($status === 401) {
            throw new QuickbooksException('QuickBooks rejected the connection. Reconnect QuickBooks on the Settings page.', authFailed: true);
        }
        if ($status === 429 || $status >= 500) {
            throw new QuickbooksException("QuickBooks is busy or down right now (status {$status}).", transient: true);
        }

        throw new QuickbooksException($this->faultMessage($response));
    }

    /** Intuit's own explanation of why it refused a request. */
    private function faultMessage(Response $response): string
    {
        $error = $response->json('Fault.Error.0');
        if (! is_array($error)) {
            return 'QuickBooks refused the request (status '.$response->status().').';
        }

        $text = trim(($error['Message'] ?? 'QuickBooks refused the request').(isset($error['Detail']) ? ': '.$error['Detail'] : ''));
        $code = $error['code'] ?? null;

        return $code ? "{$text} (code {$code})" : $text;
    }

    private function accessToken(QuickbooksConnection $connection, bool $force): string
    {
        if (! $force && $connection->access_token_expires_at->subMinute()->isFuture()) {
            return $connection->access_token;
        }

        return $this->refresh($connection->id, $force ? $connection->access_token : null)->access_token;
    }

    /**
     * Swaps the refresh token for a new access token. Under a lock, and re-reading the row inside it: another process may have
     * refreshed while this one waited, and then the token it stored is already the good one.
     *
     * @param  string|null  $rejectedToken  the access token QuickBooks just refused, when the refresh was forced
     */
    private function refresh(int $connectionId, ?string $rejectedToken): QuickbooksConnection
    {
        try {
            return Cache::lock('quickbooks-token-refresh', 30)->block(15, function () use ($connectionId, $rejectedToken) {
                $connection = QuickbooksConnection::find($connectionId)
                    ?? throw new QuickbooksException('QuickBooks is not connected.', authFailed: true);

                $stillGood = $connection->access_token_expires_at->subMinute()->isFuture()
                    && ($rejectedToken === null || $connection->access_token !== $rejectedToken);
                if ($stillGood) {
                    return $connection;
                }

                try {
                    $response = Http::asForm()
                        ->withBasicAuth((string) config('services.quickbooks.client_id'), (string) config('services.quickbooks.client_secret'))
                        ->acceptJson()
                        ->timeout(self::TIMEOUT_SECONDS)
                        ->post(config('services.quickbooks.token_url'), [
                            'grant_type' => 'refresh_token',
                            'refresh_token' => $connection->refresh_token,
                        ]);
                } catch (ConnectionException $e) {
                    throw $this->unreachable($e);
                }

                if ($response->failed()) {
                    if ($response->status() === 400 || $response->status() === 401) {
                        // The refresh token is no longer good (revoked, or unused for 100 days): only a new consent fixes it.
                        Log::warning('QuickBooks refresh token was refused.', ['status' => $response->status(), 'body' => $response->body()]);
                        $connection->update(['refresh_token_expires_at' => now()->subSecond()]);

                        throw new QuickbooksException('The QuickBooks connection has expired. Reconnect QuickBooks on the Settings page.', authFailed: true);
                    }

                    throw new QuickbooksException('QuickBooks could not refresh the connection (status '.$response->status().').', transient: true);
                }

                $tokens = $response->json();
                $connection->update([
                    'access_token' => $tokens['access_token'],
                    'access_token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
                    // Always the latest: the one just handed back replaces the old one for good.
                    'refresh_token' => $tokens['refresh_token'] ?? $connection->refresh_token,
                    'refresh_token_expires_at' => now()->addSeconds((int) ($tokens['x_refresh_token_expires_in'] ?? 8726400)),
                ]);

                return $connection->refresh();
            });
        } catch (LockTimeoutException) {
            throw new QuickbooksException('QuickBooks is busy refreshing the connection, try again shortly.', transient: true);
        }
    }
}

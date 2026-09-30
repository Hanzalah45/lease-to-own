<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\QuickbooksConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Company-wide QuickBooks Online connection — one row, always (see
 * QuickbooksConnection), so this is gated the same way admin-user management
 * is (super_admin only) rather than behind a per-module permission that
 * doesn't exist for "integrations" yet.
 */
class QuickbooksConnectionController extends Controller
{
    private const AUTHORIZE_URL = 'https://appcenter.intuit.com/connect/oauth2';

    private const SCOPE = 'com.intuit.quickbooks.accounting';

    public function status()
    {
        $connection = QuickbooksConnection::with('connectedBy:id,name')->first();

        if (! $connection) {
            return response()->json(['data' => ['connected' => false]]);
        }

        return response()->json(['data' => [
            'connected' => true,
            'realm_id' => $connection->realm_id,
            'connected_by' => $connection->connectedBy?->name,
            'connected_at' => $connection->created_at,
            'access_token_expires_at' => $connection->access_token_expires_at,
            'refresh_token_expires_at' => $connection->refresh_token_expires_at,
            'needs_reconnect' => $connection->isRefreshTokenExpired(),
        ]]);
    }

    /**
     * Returns the Intuit consent-screen URL for the frontend to redirect the
     * admin's browser to. The state token (cached with this admin's id, not
     * carried in the URL as anything guessable) is how the unauthenticated
     * callback below knows who to credit as connected_by and that the
     * request genuinely started here — Intuit's redirect back can't carry a
     * Sanctum bearer header, so the callback route can't be auth:sanctum.
     */
    public function connect()
    {
        $state = Str::random(40);
        Cache::put("quickbooks_oauth_state:{$state}", Auth::id(), now()->addMinutes(10));

        $query = http_build_query([
            'client_id' => config('services.quickbooks.client_id'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'redirect_uri' => config('services.quickbooks.redirect_uri'),
            'state' => $state,
        ]);

        return response()->json(['data' => ['url' => self::AUTHORIZE_URL.'?'.$query]]);
    }

    public function disconnect()
    {
        $connection = QuickbooksConnection::first();
        if (! $connection) {
            return response()->json(['message' => 'Not connected.'], 404);
        }

        // Best-effort — the local record is removed either way, since a
        // revoke failure (network blip, already-expired token) shouldn't
        // block the admin from disconnecting on our end.
        Http::asForm()->withBasicAuth(
            config('services.quickbooks.client_id'),
            config('services.quickbooks.client_secret'),
        )->post('https://developer.api.intuit.com/v2/oauth2/tokens/revoke', [
            'token' => $connection->refresh_token,
        ]);

        $connection->delete();

        return response()->json(['message' => 'Disconnected.']);
    }
}

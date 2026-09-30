<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuickbooksConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Intuit redirects the admin's browser here after the consent screen — a
 * plain top-level GET, not an XHR the SPA controls, so it can't carry a
 * Sanctum bearer header and can't sit behind auth:sanctum. The state token
 * (minted and cached by Admin\QuickbooksConnectionController::connect())
 * is what proves this callback belongs to a real, recent, super_admin-
 * initiated connect attempt rather than an arbitrary request to this URL.
 */
class QuickbooksOAuthCallbackController extends Controller
{
    private const TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    public function __invoke(Request $request)
    {
        $frontendBase = rtrim(config('app.frontend_url'), '/').'/admin/settings';

        $state = (string) $request->query('state');
        $adminUserId = $state !== '' ? Cache::pull("quickbooks_oauth_state:{$state}") : null;

        if (! $adminUserId) {
            return redirect($frontendBase.'?quickbooks=invalid_state');
        }

        if ($request->query('error') || ! $request->query('code') || ! $request->query('realmId')) {
            return redirect($frontendBase.'?quickbooks=denied');
        }

        $response = Http::asForm()->withBasicAuth(
            config('services.quickbooks.client_id'),
            config('services.quickbooks.client_secret'),
        )->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $request->query('code'),
            'redirect_uri' => config('services.quickbooks.redirect_uri'),
        ]);

        if ($response->failed()) {
            Log::error('QuickBooks token exchange failed.', ['status' => $response->status(), 'body' => $response->body()]);

            return redirect($frontendBase.'?quickbooks=exchange_failed');
        }

        $tokens = $response->json();

        // One row, always — this app has exactly one QuickBooks company, so
        // a fresh connect replaces whatever was there before.
        QuickbooksConnection::query()->delete();
        QuickbooksConnection::create([
            'realm_id' => $request->query('realmId'),
            'access_token' => $tokens['access_token'],
            'access_token_expires_at' => now()->addSeconds((int) $tokens['expires_in']),
            'refresh_token' => $tokens['refresh_token'],
            'refresh_token_expires_at' => now()->addSeconds((int) $tokens['x_refresh_token_expires_in']),
            'connected_by' => $adminUserId,
        ]);

        return redirect($frontendBase.'?quickbooks=connected');
    }
}

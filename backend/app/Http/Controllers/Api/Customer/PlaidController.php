<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Notifications\BankVerifiedNotification;
use App\Services\PlaidClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Bank verification via Plaid Link, for the authenticated customer's own
 * profile. The customer connects their own bank — this never runs on an
 * admin's behalf. Full risk-scoring off the linked account data is
 * Milestone 3; this wires up the connection itself.
 */
class PlaidController extends Controller
{
    public function __construct(private readonly PlaidClient $plaid) {}

    public function linkToken(Request $request)
    {
        try {
            $linkToken = $this->plaid->createLinkToken($request->user()->id, $request->user()->name);

            return response()->json(['link_token' => $linkToken]);
        } catch (RuntimeException $e) {
            Log::error('Plaid link token creation failed', ['message' => $e->getMessage()]);

            return response()->json(['message' => 'Could not start bank verification. Please try again.'], 502);
        }
    }

    public function exchange(Request $request)
    {
        $data = $request->validate([
            'public_token' => ['required', 'string'],
        ]);

        try {
            $exchange = $this->plaid->exchangePublicToken($data['public_token']);
            $accounts = $this->plaid->getAccounts($exchange['access_token']);
        } catch (RuntimeException $e) {
            Log::error('Plaid token exchange failed', ['message' => $e->getMessage()]);

            return response()->json(['message' => 'Could not verify your bank connection. Please try again.'], 502);
        }

        $profile = $request->user()->customerProfile()->firstOrCreate([]);
        $profile->update([
            'plaid_item_id' => $exchange['item_id'],
            'plaid_access_token' => $exchange['access_token'],
            'bank_verified_at' => now(),
        ]);

        $request->user()->notify(new BankVerifiedNotification());

        return response()->json([
            'data' => [
                'verified_at' => $profile->bank_verified_at,
                'accounts' => array_map(
                    fn ($account) => [
                        'name' => $account['name'],
                        'mask' => $account['mask'],
                        'subtype' => $account['subtype'],
                    ],
                    $accounts,
                ),
            ],
        ]);
    }

    public function status(Request $request)
    {
        $profile = $request->user()->customerProfile;

        return response()->json([
            'data' => [
                'connected' => (bool) $profile?->bank_verified_at,
                'verified_at' => $profile?->bank_verified_at,
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BankVerificationSigner;
use App\Services\PlaidClient;
use App\Services\RiskScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The signed-link counterpart to Customer\PlaidController — reached from the
 * "Request bank verification" admin action's emailed link rather than an
 * authenticated session, since a guest-originated customer has no working
 * login yet (see BankVerificationSigner). Every request here re-validates
 * the signature itself; nothing here trusts Sanctum.
 */
class PublicPlaidVerificationController extends Controller
{
    public function __construct(private readonly PlaidClient $plaid) {}

    public function linkToken(Request $request)
    {
        $customer = $this->resolveSignedCustomer($request);

        try {
            $linkToken = $this->plaid->createLinkToken($customer->id, $customer->name);

            return response()->json(['link_token' => $linkToken]);
        } catch (RuntimeException $e) {
            Log::error('Plaid link token creation failed (signed link)', ['message' => $e->getMessage()]);

            return response()->json(['message' => 'Could not start bank verification. Please try again.'], 502);
        }
    }

    public function exchange(Request $request)
    {
        $customer = $this->resolveSignedCustomer($request);

        $data = $request->validate([
            'public_token' => ['required', 'string'],
        ]);

        try {
            $exchange = $this->plaid->exchangePublicToken($data['public_token']);
            $accounts = $this->plaid->getAccounts($exchange['access_token']);
        } catch (RuntimeException $e) {
            Log::error('Plaid token exchange failed (signed link)', ['message' => $e->getMessage()]);

            return response()->json(['message' => 'Could not verify your bank connection. Please try again.'], 502);
        }

        $result = RiskScoringService::recordBankVerification($customer, $exchange['item_id'], $exchange['access_token'], $accounts);

        return response()->json(['data' => $result]);
    }

    private function resolveSignedCustomer(Request $request): User
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'hash' => ['required', 'string'],
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string'],
        ]);

        $customer = User::find($data['id']);
        $linkIsValid = $customer
            && $customer->isCustomer()
            && hash_equals(sha1($customer->email), $data['hash'])
            && BankVerificationSigner::isValid($data['id'], $data['hash'], $data['expires'], $data['signature']);

        abort_unless($linkIsValid, 422, 'This verification link is invalid or has expired.');

        return $customer;
    }
}

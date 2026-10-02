<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Models\User;
use App\Services\CommonValidationRules;
use App\Services\ContractSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Consolidated guest onboarding (client, Joel, 2026-10-02): account creation
 * now happens as the first of two separate steps reached from the contract
 * preview link (the second being Customer\ContractController::store(), once
 * this issues a real session) — rather than at first payment/pickup like
 * AccountSetupController. Deliberately reuses ContractSigner's existing
 * 5-param signed link (the same one RequestContractSignatureNotification
 * already emails) instead of minting a new Signer: no breaking HMAC format
 * change, and it means Admin\ApplicationController's attachLease()/
 * resendContractSigningLink() need no changes to carry this new flow.
 */
class PublicAccountActivationController extends Controller
{
    public function activate(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'lease' => ['required', 'integer'],
            'hash' => ['required', 'string'],
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string'],
            'password' => array_merge(CommonValidationRules::password(), ['confirmed']),
        ]);

        $customer = User::find($data['id']);
        $lease = LeaseAgreement::find($data['lease']);
        $linkIsValid = $customer
            && $lease
            && $lease->customer_id === $customer->id
            && $customer->isCustomer()
            && hash_equals(sha1($customer->email), $data['hash'])
            && ContractSigner::isValid($data['id'], $data['lease'], $data['hash'], $data['expires'], $data['signature']);

        abort_unless($linkIsValid, 422, 'This link is invalid or has expired.');

        // A link this way stays valid for its full TTL — without this, it
        // would double as a standing passwordless-login mechanism for anyone
        // who already finished setup. One-time-use, same assumption
        // AccountSetupController already makes.
        abort_unless($customer->status === 'pending', 422, 'This account has already been set up. Please log in.');

        $customer->forceFill([
            'password' => Hash::make($data['password']),
            'status' => 'active',
            'email_verified_at' => $customer->email_verified_at ?? now(),
        ])->save();

        return response()->json([
            'user' => $customer->fresh(),
            'token' => $customer->createToken('auth_token')->plainTextToken,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountSetupSigner;
use App\Services\CommonValidationRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Lets a guest-originated customer set their first real password, reached
 * from the signed link ActivateAccountNotification emails once their first
 * payment is marked paid (Admin\PaymentController::update()). Their shadow
 * account (ApplicationCreationService::createGuestApplication) starts with
 * an unusable random password and status "pending" — this is the only way
 * they can ever log in.
 */
class AccountSetupController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'hash' => ['required', 'string'],
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string'],
            'password' => array_merge(CommonValidationRules::password(), ['confirmed']),
        ]);

        $user = User::find($data['id']);
        $linkIsValid = $user
            && $user->isCustomer()
            && hash_equals(sha1($user->email), $data['hash'])
            && AccountSetupSigner::isValid($data['id'], $data['hash'], $data['expires'], $data['signature']);

        if (! $linkIsValid) {
            return response()->json(['message' => 'This account setup link is invalid or has expired.'], 422);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'status' => 'active',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return response()->json(['message' => 'Your account is set up. You can now sign in.']);
    }
}

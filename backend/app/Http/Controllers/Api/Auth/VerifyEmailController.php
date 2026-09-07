<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationSigner;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'hash' => ['required', 'string'],
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string'],
        ]);

        $user = User::find($data['id']);
        $linkIsValid = $user
            && hash_equals(sha1($user->email), $data['hash'])
            && EmailVerificationSigner::isValid($data['id'], $data['hash'], $data['expires'], $data['signature']);

        if (! $linkIsValid) {
            return response()->json(['message' => 'This verification link is invalid or has expired.'], 422);
        }

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now(), 'status' => 'active'])->save();
        }

        return response()->json(['message' => 'Your email is verified. You can now log in.']);
    }
}

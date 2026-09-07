<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\CommonValidationRules;
use App\Services\EmailVerificationSigner;
use Illuminate\Http\Request;

class ResendVerificationEmailController extends Controller
{
    /**
     * Same anti-enumeration shape as ForgotPasswordController: identical
     * response whether or not the email matches an unverified account.
     */
    public function __invoke(Request $request)
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:'.CommonValidationRules::EMAIL_MAX]]);

        $user = User::where('email', $request->input('email'))->first();
        if ($user && ! $user->email_verified_at) {
            $user->notify(new VerifyEmailNotification(EmailVerificationSigner::urlFor($user)));
        }

        return response()->json([
            'message' => 'If that email needs verifying, a new link has been sent.',
        ]);
    }
}

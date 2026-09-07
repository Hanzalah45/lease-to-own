<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CommonValidationRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:'.CommonValidationRules::EMAIL_MAX],
            // Bcrypt only hashes the first 72 bytes anyway — capping here
            // avoids spending hashing time on an arbitrarily long payload.
            'password' => ['required', 'string', 'max:'.CommonValidationRules::PASSWORD_MAX],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $credentials = $validator->validated();

        // Deliberately the same message/field whether the email doesn't
        // exist or the password is wrong — matches ForgotPasswordController's
        // anti-enumeration design, so a wrong-email attempt can't be told
        // apart from a wrong-password one.
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->status !== 'active') {
            Auth::logout();

            $message = ($user->status === 'pending' && ! $user->email_verified_at)
                ? 'Please verify your email before logging in.'
                : 'This account is not active.';

            return response()->json(['message' => $message], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }
}

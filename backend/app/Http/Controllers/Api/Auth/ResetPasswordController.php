<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class ResetPasswordController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        $status = Password::reset($data, function ($user, $password) {
            $user->update(['password' => Hash::make($password)]);
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [$this->friendlyMessage($status)]]);
        }

        return response()->json(['message' => 'Your password has been reset. You can now sign in.']);
    }

    private function friendlyMessage(string $status): string
    {
        return match ($status) {
            Password::INVALID_USER => 'We could not find an account with that email.',
            Password::INVALID_TOKEN => 'This reset link is invalid or has expired. Request a new one.',
            Password::RESET_THROTTLED => 'Please wait a minute before requesting another reset link.',
            default => 'Could not reset your password. Please try again.',
        };
    }
}

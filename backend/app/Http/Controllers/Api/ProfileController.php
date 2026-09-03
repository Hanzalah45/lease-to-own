<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\AccountSecurityUpdatedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /** Any authenticated user (customer, admin, or super admin) updating their own account. */
    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'password' => ['sometimes', 'string', 'min:8', 'max:72', 'confirmed'],
            'current_password' => ['sometimes', 'string'],
        ]);

        $emailChanged = isset($data['email']) && $data['email'] !== $user->email;
        $passwordChanged = isset($data['password']);

        // A session can otherwise change the account's email with no
        // re-confirmation, then take the account over via "forgot password"
        // sent to that new address — require the current password for
        // either sensitive change, not just a password change.
        if ($emailChanged || $passwordChanged) {
            if (empty($data['current_password'])) {
                throw ValidationException::withMessages(['current_password' => ['Current password is required to make this change.']]);
            }
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => ['Current password is incorrect.']]);
            }
        }

        $user->update(collect($data)->only(['name', 'email', 'phone', 'password'])->toArray());

        $changes = array_filter([
            $emailChanged ? 'email address' : null,
            $passwordChanged ? 'password' : null,
        ]);
        if ($changes) {
            $user->notify(new AccountSecurityUpdatedNotification('Your '.implode(' and ', $changes).' was changed.'));
        }

        return response()->json(['user' => $user->fresh()->load(['customerProfile', 'adminPermissions'])]);
    }
}

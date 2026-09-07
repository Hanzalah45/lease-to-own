<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\AccountSecurityUpdatedNotification;
use App\Services\CommonValidationRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /** Any authenticated user (customer, admin, or super admin) updating their own account. */
    public function update(Request $request)
    {
        $user = $request->user();

        // Email is intentionally not editable here (client decision,
        // 2026-09-04): a self-service, unverified email change was judged a
        // bigger risk than the friction of asking an admin — an admin can
        // still change a customer's email (Admin\CustomerController), with
        // that change notified to the customer. Not in the validation rules
        // at all, so a posted `email` is silently ignored rather than
        // erroring, matching how AdminUserController::update() already
        // omits it from admin-editing-admin.
        $data = $request->validate([
            'name' => CommonValidationRules::name(required: false),
            'phone' => array_merge(['sometimes'], CommonValidationRules::phone()),
            'password' => array_merge(CommonValidationRules::password(required: false), ['confirmed']),
            'current_password' => ['sometimes', 'string', 'max:'.CommonValidationRules::PASSWORD_MAX],
        ]);

        $passwordChanged = isset($data['password']);
        $nameChanged = isset($data['name']) && $data['name'] !== $user->name;
        $phoneChanged = array_key_exists('phone', $data) && $data['phone'] !== $user->phone;

        // A session could otherwise change the password with no
        // re-confirmation and lock the real owner out — require the current
        // password first.
        if ($passwordChanged) {
            if (empty($data['current_password'])) {
                throw ValidationException::withMessages(['current_password' => ['Current password is required to make this change.']]);
            }
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => ['Current password is incorrect.']]);
            }
        }

        $user->update(collect($data)->only(['name', 'phone', 'password'])->toArray());

        $changes = array_filter([
            $passwordChanged ? 'password' : null,
            $nameChanged ? 'name' : null,
            $phoneChanged ? 'phone number' : null,
        ]);
        if ($changes) {
            $user->notify(new AccountSecurityUpdatedNotification('Your '.implode(', ', $changes).' was changed.'));
        }

        return response()->json(['user' => $user->fresh()->load(['customerProfile', 'adminPermissions'])]);
    }
}

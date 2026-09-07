<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Notifications\NewCustomerRegisteredNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\CommonValidationRules;
use App\Services\EmailVerificationSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

class RegisterController extends Controller
{
    /**
     * Self-service registration for customers only.
     * Admin accounts are created by an existing admin via Api/Admin/AdminUserController,
     * never through this public endpoint.
     */
    public function __invoke(Request $request)
    {
        $data = Validator::make($request->all(), [
            'name' => CommonValidationRules::name(),
            'email' => CommonValidationRules::email('unique:users,email'),
            'phone' => CommonValidationRules::phone(),
            'password' => array_merge(CommonValidationRules::password(), ['confirmed']),
        ])->validate();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => User::ROLE_CUSTOMER,
            // Stays "pending" (LoginController already rejects any non-active status)
            // until the verification link below is clicked — see VerifyEmailController.
            'status' => 'pending',
        ]);

        CustomerProfile::create(['user_id' => $user->id]);

        $user->notify(new VerifyEmailNotification(EmailVerificationSigner::urlFor($user)));

        // Its action_url points at /admin/customers/{id}, which is gated by
        // application_review — so only admins who can actually open it get notified.
        $staff = User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($inner) {
                        $inner->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::APPLICATION_REVIEW));
                    });
            })->get();
        Notification::send($staff, new NewCustomerRegisteredNotification($user));

        // No token: the account isn't active until the email is verified, so
        // there's nothing useful to authenticate with yet — see /auth/login.
        return response()->json([
            'message' => 'Account created. Check your email to verify and activate it before logging in.',
            'user' => $user,
        ], 201);
    }
}

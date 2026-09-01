<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Notifications\NewCustomerRegisteredNotification;
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
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => User::ROLE_CUSTOMER,
            'status' => 'active',
        ]);

        CustomerProfile::create(['user_id' => $user->id]);

        $staff = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])->get();
        Notification::send($staff, new NewCustomerRegisteredNotification($user));

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }
}

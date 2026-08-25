<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminUserController extends Controller
{
    /**
     * List every admin account and its permission set. The team isn't capped
     * at a fixed number of admins — any admin can create more here.
     */
    public function index()
    {
        $admins = User::where('role', User::ROLE_ADMIN)->with('adminPermissions')->get();

        return response()->json(['data' => $admins]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'permissions' => ['array'],
            'permissions.*' => ['in:'.implode(',', [
                AdminPermission::APPLICATION_REVIEW,
                AdminPermission::RISK_ASSESSMENT,
                AdminPermission::CONTRACT_GENERATION,
                AdminPermission::EQUIPMENT_TRACKING,
                AdminPermission::PAYMENT_TRACKING,
            ])],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $admin = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);

        foreach ($data['permissions'] ?? [] as $permission) {
            AdminPermission::create(['user_id' => $admin->id, 'permission' => $permission]);
        }

        return response()->json(['data' => $admin->load('adminPermissions')], 201);
    }

    public function show(User $adminUser)
    {
        return response()->json(['data' => $adminUser->load('adminPermissions')]);
    }

    /**
     * Update an admin's profile, status, and permission set in one call.
     */
    public function update(Request $request, User $adminUser)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'status' => ['sometimes', 'in:active,suspended,pending'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['in:'.implode(',', [
                AdminPermission::APPLICATION_REVIEW,
                AdminPermission::RISK_ASSESSMENT,
                AdminPermission::CONTRACT_GENERATION,
                AdminPermission::EQUIPMENT_TRACKING,
                AdminPermission::PAYMENT_TRACKING,
            ])],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $adminUser->update(collect($data)->only(['name', 'phone', 'status'])->toArray());

        if (array_key_exists('permissions', $data)) {
            $adminUser->adminPermissions()->delete();
            foreach ($data['permissions'] as $permission) {
                AdminPermission::create(['user_id' => $adminUser->id, 'permission' => $permission]);
            }
        }

        return response()->json(['data' => $adminUser->load('adminPermissions')]);
    }

    public function destroy(User $adminUser)
    {
        $adminUser->delete();

        return response()->json(null, 204);
    }
}

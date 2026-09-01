<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminAccountCreatedMail;
use App\Models\AdminPermission;
use App\Models\User;
use App\Notifications\AdminAccountCreatedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Super-admin-only: create and manage Admin accounts. Admins never
 * self-register — the super admin sets an initial password here, and it's
 * emailed to the admin so they have a way to receive it.
 */
class AdminUserController extends Controller
{
    /**
     * List every admin account and its restriction list. No restriction
     * rows means that admin has full access. The team isn't capped at a
     * fixed number of admins — the super admin can create more here.
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
            // Restriction list — leave empty/omitted for full access.
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

        Mail::to($admin->email)->send(new AdminAccountCreatedMail($admin, $data['password']));
        $admin->notify(new AdminAccountCreatedNotification());

        return response()->json(['data' => $admin->load('adminPermissions')], 201);
    }

    public function show(User $adminUser)
    {
        $this->assertIsAdmin($adminUser);

        return response()->json(['data' => $adminUser->load('adminPermissions')]);
    }

    /**
     * Update an admin's profile, status, and permission set in one call.
     */
    public function update(Request $request, User $adminUser)
    {
        $this->assertIsAdmin($adminUser);

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
        $this->assertIsAdmin($adminUser);

        $adminUser->delete();

        return response()->json(null, 204);
    }

    /**
     * This endpoint only ever targets Admin accounts — never a customer or
     * another super admin, even though route-model binding would resolve
     * any user ID.
     */
    private function assertIsAdmin(User $user): void
    {
        abort_unless($user->isAdmin(), 404);
    }
}

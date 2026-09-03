<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AccountSecurityUpdatedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Full customer management for admins with application_review access:
 * list, view, create, edit (including email/password), and soft-delete.
 * Deleting a customer never hard-removes the row — it's recoverable via
 * the `deleted_at` timestamp, and disappears from the directory by default.
 */
class CustomerController extends Controller
{
    public function index()
    {
        $customers = User::where('role', User::ROLE_CUSTOMER)
            ->with('customerProfile.updatedBy:id,name')
            ->latest()
            ->get();

        return response()->json(['data' => $customers]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:2'],
            'zip' => ['nullable', 'string', 'max:10'],
            'date_of_birth' => ['nullable', 'date'],
            'internal_notes' => ['nullable', 'string'],
        ]);

        $customer = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => User::ROLE_CUSTOMER,
            'status' => 'active',
        ]);

        $customer->customerProfile()->create([
            'address_line_1' => $data['address_line_1'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip' => $data['zip'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'internal_notes' => $data['internal_notes'] ?? null,
        ]);

        return response()->json(['data' => $customer->load('customerProfile')], 201);
    }

    public function show(User $customer)
    {
        $this->assertIsCustomer($customer);

        return response()->json([
            'data' => $customer->load(['customerProfile.updatedBy:id,name', 'applications', 'leaseAgreements', 'riskProfile']),
        ]);
    }

    public function update(Request $request, User $customer)
    {
        $this->assertIsCustomer($customer);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($customer->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:72'],
            'status' => ['sometimes', 'in:active,suspended,pending'],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'state' => ['sometimes', 'nullable', 'string', 'max:2'],
            'zip' => ['sometimes', 'nullable', 'string', 'max:10'],
            'date_of_birth' => ['sometimes', 'nullable', 'date'],
            'internal_notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $emailChanged = isset($data['email']) && $data['email'] !== $customer->email;
        $passwordChanged = ! empty($data['password']);
        $statusChanged = isset($data['status']) && $data['status'] !== $customer->status;

        $userFields = collect($data)->only(['name', 'email', 'phone', 'status'])->toArray();
        if ($passwordChanged) {
            $userFields['password'] = Hash::make($data['password']);
        }
        if ($userFields) {
            $customer->update($userFields);
        }

        // Sanctum tokens never expire and nothing re-checks status on later
        // requests — without this, a suspended customer keeps full API access
        // until they happen to log out on their own.
        if ($statusChanged && $data['status'] !== 'active') {
            $customer->tokens()->delete();
        }

        $changes = array_filter([
            $emailChanged ? 'email address' : null,
            $passwordChanged ? 'password' : null,
        ]);
        if ($changes) {
            $customer->notify(new AccountSecurityUpdatedNotification('Your '.implode(' and ', $changes).' was changed by an admin.'));
        }

        $profileFields = collect($data)
            ->only(['address_line_1', 'city', 'state', 'zip', 'date_of_birth', 'internal_notes'])
            ->toArray();
        if ($profileFields) {
            $customer->customerProfile()->updateOrCreate(
                ['user_id' => $customer->id],
                array_merge($profileFields, ['updated_by' => Auth::id()]),
            );
        }

        return response()->json(['data' => $customer->fresh()->load(['customerProfile.updatedBy:id,name'])]);
    }

    public function destroy(User $customer)
    {
        $this->assertIsCustomer($customer);

        $customer->delete();

        return response()->json(null, 204);
    }

    /**
     * This endpoint only ever targets Customer accounts — never an admin or
     * super admin, even though route-model binding would resolve any user ID.
     */
    private function assertIsCustomer(User $user): void
    {
        abort_unless($user->isCustomer(), 404);
    }
}

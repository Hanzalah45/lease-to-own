<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;

/**
 * Read-only customer directory for admins. Editing a customer's own data
 * (application, payment, lease, equipment records) happens inside those
 * respective flows per the reference doc, not here — this is just the list
 * and profile view.
 */
class CustomerController extends Controller
{
    public function index()
    {
        $customers = User::where('role', User::ROLE_CUSTOMER)
            ->with('customerProfile')
            ->latest()
            ->get();

        return response()->json(['data' => $customers]);
    }

    public function show(User $customer)
    {
        abort_unless($customer->isCustomer(), 404);

        return response()->json([
            'data' => $customer->load(['customerProfile', 'applications', 'leaseAgreements', 'riskProfile']),
        ]);
    }
}

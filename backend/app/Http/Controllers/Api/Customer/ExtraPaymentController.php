<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Services\CustomerPaymentCharger;
use App\Services\LeaseEngine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A customer pays extra toward the early purchase price from their own screen (client, Joel, 2026-10-16: "how are they
 * able to make extra payments toward the principal when they want to?"). The money becomes the lease's Additional Funds
 * once it is paid (contract Section 19), which lowers the early purchase price shown in the portal.
 */
class ExtraPaymentController extends Controller
{
    public function show(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);
        $leaseAgreement->load(['application', 'customer']);

        return response()->json(['data' => CustomerPaymentCharger::extraPaymentOptions($leaseAgreement) + [
            'epo_today' => LeaseEngine::epoToday($leaseAgreement),
            // What the customer has put toward the early purchase price (contract Section 19), and every movement of it, newest first.
            'additional_funds' => round((float) $leaseAgreement->additional_funds, 2),
            'history' => $leaseAgreement->additionalFundsEntries()->get()->sortByDesc('id')->values()->map(fn ($e) => [
                'id' => $e->id,
                'kind' => $e->kind,
                'amount' => $e->amount,
                'balance_after' => $e->balance_after,
                'description' => $e->description,
                'created_at' => $e->created_at,
            ]),
        ]]);
    }

    public function store(Request $request, LeaseAgreement $leaseAgreement, CustomerPaymentCharger $charger)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);

        $data = $request->validate([
            // The early purchase price can never be more than the cash price of the equipment, far below $100,000;
            // the exact range is checked against the live price on the server.
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'method' => ['required', Rule::in(['bank', 'card'])],
            // The total shown on screen: refused with a 409 if the price moved underneath the customer.
            'expected_total_cents' => ['nullable', 'integer', 'min:1'],
        ]);
        $leaseAgreement->load(['application', 'customer']);

        $result = $charger->payExtra(
            $leaseAgreement,
            (float) $data['amount'],
            $data['method'],
            isset($data['expected_total_cents']) ? (int) $data['expected_total_cents'] : null,
        );

        return response()->json(['data' => $result + ['epo_today' => LeaseEngine::epoToday($leaseAgreement->fresh())]]);
    }
}

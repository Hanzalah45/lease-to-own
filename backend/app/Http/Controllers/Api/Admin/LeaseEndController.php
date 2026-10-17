<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Services\LeaseEnder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff end a lease that was already picked up: equipment returned, early purchase completed, or another reason (client,
 * Joel, 2026-10-16). See LeaseEnder for exactly what it does and what it leaves for a person.
 */
class LeaseEndController extends Controller
{
    public function store(Request $request, LeaseAgreement $leaseAgreement, LeaseEnder $ender)
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(LeaseEnder::REASONS)],
            // A short explanation for the file: a sentence or two.
            'note' => ['nullable', 'string', 'max:500'],
            // An early purchase is only completed once the sales tax due on it has been collected.
            'tax_collected' => ['exclude_unless:reason,'.LeaseEnder::REASON_PURCHASED, 'accepted'],
        ]);

        $result = $ender->end($leaseAgreement, $data['reason'], $data['note'] ?? null, $request->user());

        return response()->json(['data' => $result + ['ended_at' => $leaseAgreement->fresh()->ended_at]]);
    }
}

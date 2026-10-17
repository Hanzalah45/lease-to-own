<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Services\BillingClock;
use App\Services\CustomerPaymentCharger;
use App\Services\LeaseEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Staff record an extra payment a customer made in the office, toward the early purchase price (client, Joel, 2026-10-16;
 * the plan: "a cash payment is recorded manually by an admin"). It lands as Additional Funds like one the customer
 * pays from their phone (contract Section 19), so the early purchase price updates the same way.
 */
class ExtraPaymentController extends Controller
{
    public function store(Request $request, LeaseAgreement $leaseAgreement)
    {
        $data = $request->validate([
            // Same bound as the customer's own form: the early purchase price is far below $100,000, and the exact
            // range is checked against the live price below.
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'method' => ['required', Rule::in(['cash', 'check', 'other'])],
        ]);
        $admin = $request->user();

        $payment = DB::transaction(function () use ($leaseAgreement, $data, $admin) {
            LeaseAgreement::whereKey($leaseAgreement->id)->lockForUpdate()->firstOrFail();
            $lease = LeaseAgreement::with(['application', 'customer'])->findOrFail($leaseAgreement->id);
            abort_if($reason = CustomerPaymentCharger::extraPaymentBlocker($lease, false), 422, $reason);

            $max = round(max(0.0, LeaseEngine::epoToday($lease)), 2);
            $amount = round((float) $data['amount'], 2);
            abort_if($amount > $max, 422, sprintf('The most that can be paid toward the early purchase price is $%s.', number_format($max, 2)));

            $payment = Payment::create([
                'lease_agreement_id' => $lease->id,
                'type' => Payment::TYPE_EXTRA_PAYMENT,
                'amount' => $amount,
                'due_date' => BillingClock::todayDate(),
                'status' => Payment::STATUS_PENDING,
                'method' => $data['method'],
            ]);
            $payment = LeaseEngine::applyPaymentStatusChange($payment, Payment::STATUS_PAID, $data['method'], $admin->id);

            $lease->application?->dealerNotes()->create([
                'author_user_id' => $admin->id,
                'text' => sprintf(
                    'Extra payment of $%s received by %s toward the early purchase price. Recorded by %s.',
                    number_format($amount, 2),
                    $data['method'],
                    $admin->name,
                ),
            ]);

            return $payment;
        });

        return response()->json(['data' => [
            'payment' => $payment,
            'epo_today' => LeaseEngine::epoToday($leaseAgreement->fresh()),
        ]]);
    }
}

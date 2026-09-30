<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\LeaseEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with(['leaseAgreement.customer:id,name,email', 'recordedBy:id,name'])->latest('due_date');

        if ($request->filled('lease_agreement_id')) {
            $query->where('lease_agreement_id', $request->integer('lease_agreement_id'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(Payment $payment)
    {
        return response()->json(['data' => $payment->load(['leaseAgreement.customer:id,name,email', 'recordedBy:id,name'])]);
    }

    /** Admins record manual payments here (cash/check/ACH confirmation), or a processor webhook records one automatically — see LeaseEngine::applyPaymentStatusChange(). */
    public function update(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Payment::STATUS_PENDING, Payment::STATUS_PAID, Payment::STATUS_FAILED, Payment::STATUS_REFUNDED])],
            'method' => ['sometimes', 'nullable', Rule::in(['ach', 'card', 'cash', 'other'])],
        ]);

        $payment = LeaseEngine::applyPaymentStatusChange($payment, $data['status'], $data['method'] ?? null, Auth::id());

        return response()->json(['data' => $payment->load('recordedBy:id,name')]);
    }
}

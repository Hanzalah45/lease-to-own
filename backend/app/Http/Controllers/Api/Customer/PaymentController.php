<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $payments = Payment::whereHas(
            'leaseAgreement',
            fn ($query) => $query->where('customer_id', $request->user()->id),
        )->with('leaseAgreement.equipmentUnit')->latest('due_date')->get();

        return response()->json(['data' => $payments]);
    }

    public function show(Request $request, Payment $payment)
    {
        abort_unless($payment->leaseAgreement->customer_id === $request->user()->id, 404);

        return response()->json(['data' => $payment->load('leaseAgreement.equipmentUnit')]);
    }
}

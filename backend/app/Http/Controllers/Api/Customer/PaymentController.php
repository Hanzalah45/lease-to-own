<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Services\CustomerPaymentCharger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $payments = Payment::whereHas(
            'leaseAgreement',
            fn ($query) => $query->where('customer_id', $request->user()->id),
        )->with('leaseAgreement.equipmentUnit')->latest('due_date')->get();

        $leases = LeaseAgreement::where('customer_id', $request->user()->id)
            ->with(['application:id,status', 'customer:id,status'])
            ->get()
            ->keyBy('id');

        // `pay` says whether a due monthly payment can be paid from here right now, with both prices and the
        // saved methods that can be used (see CustomerPaymentCharger::options).
        return response()->json(['data' => $payments->map(fn (Payment $payment) => array_merge($payment->toArray(), [
            'pay' => ($lease = $leases->get($payment->lease_agreement_id)) ? CustomerPaymentCharger::options($payment, $lease) : null,
        ]))]);
    }

    public function show(Request $request, Payment $payment)
    {
        abort_unless($payment->leaseAgreement->customer_id === $request->user()->id, 404);

        return response()->json(['data' => $payment->load('leaseAgreement.equipmentUnit')]);
    }

    /** The customer pays a due monthly payment themselves, with the saved method they chose. */
    public function pay(Request $request, Payment $payment, CustomerPaymentCharger $charger)
    {
        abort_unless($payment->leaseAgreement->customer_id === $request->user()->id, 404);

        $data = $request->validate([
            'method' => ['nullable', Rule::in(['bank', 'card'])],
            // The total shown on screen: refused with a 409 if the price moved underneath the customer.
            'expected_total_cents' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $charger->pay(
            $payment,
            $data['method'] ?? null,
            isset($data['expected_total_cents']) ? (int) $data['expected_total_cents'] : null,
        );

        return response()->json(['data' => $result]);
    }
}

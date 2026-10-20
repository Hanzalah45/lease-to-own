<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentPart;
use App\Services\CustomerPaymentCharger;
use App\Services\PaymentSplitter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $payments = Payment::whereHas(
            'leaseAgreement',
            fn ($query) => $query->where('customer_id', $request->user()->id),
        )->with(['leaseAgreement.equipmentUnit', 'parts'])->latest('due_date')->get();

        $leases = LeaseAgreement::where('customer_id', $request->user()->id)
            ->with(['application:id,status', 'customer:id,status'])
            ->get()
            ->keyBy('id');

        // `pay` says whether a due monthly payment can be paid from here right now, with both prices and the
        // saved methods that can be used (see CustomerPaymentCharger::options).
        return response()->json(['data' => $payments->map(fn (Payment $payment) => array_merge($payment->toArray(), [
            // The pieces a payment was paid in, as the customer sees them (no staff, no Stripe ids).
            'parts' => $payment->parts->map(fn ($part) => $part->only(['id', 'method', 'amount', 'card_fee_amount', 'status', 'paid_date', 'created_at']))->values(),
            'pay' => ($lease = $leases->get($payment->lease_agreement_id)) ? CustomerPaymentCharger::options($payment, $lease) : null,
        ]))]);
    }

    public function show(Request $request, Payment $payment)
    {
        abort_unless($payment->leaseAgreement->customer_id === $request->user()->id, 404);

        return response()->json(['data' => $payment->load('leaseAgreement.equipmentUnit')]);
    }

    /** The customer pays a due monthly payment themselves, with the saved method they chose. */
    public function pay(Request $request, Payment $payment, CustomerPaymentCharger $charger, PaymentSplitter $splitter)
    {
        abort_unless($payment->leaseAgreement->customer_id === $request->user()->id, 404);

        $data = $request->validate([
            'method' => ['nullable', Rule::in(['bank', 'card'])],
            // The total shown on screen: refused with a 409 if the price moved underneath the customer.
            'expected_total_cents' => ['nullable', 'integer', 'min:1'],
            // Pay only part of what is due (client, Joel, 2026-10-19 checklist, section 7). Left out: pay everything that is left.
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:100000'],
        ]);

        // A part: less than what can still be charged. The rest stays due, and is paid later in one go or in more parts.
        if (isset($data['amount'])) {
            $chargeable = $payment->chargeableAmount();
            if ((int) round((float) $data['amount'] * 100) < (int) round($chargeable * 100)) {
                $lease = $payment->leaseAgreement;
                $type = $data['method'] ?? ($lease->paymentMethodFor(null)['type'] ?? null);
                abort_unless($type, 422, $lease->methodRefusal(null));

                $partResult = $splitter->charge(
                    $payment,
                    $type,
                    (float) $data['amount'],
                    isset($data['expected_total_cents']) ? (int) $data['expected_total_cents'] : null,
                    $request->user(),
                    PaymentPart::BY_CUSTOMER,
                );

                return response()->json(['data' => [
                    'payment' => $payment->fresh(['parts']),
                    'status' => $partResult['status'],
                    'message' => $partResult['message'],
                    'client_secret' => $partResult['client_secret'],
                ]]);
            }
        }

        $result = $charger->pay(
            $payment,
            $data['method'] ?? null,
            isset($data['expected_total_cents']) ? (int) $data['expected_total_cents'] : null,
        );

        return response()->json(['data' => $result]);
    }
}

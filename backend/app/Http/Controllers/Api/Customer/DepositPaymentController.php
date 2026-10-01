<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Services\StripeDepositPaymentService;
use Illuminate\Http\Request;

/**
 * Real Stripe deposit charging for an already-logged-in customer — the
 * signed-link counterpart (for a guest-originated customer with no usable
 * password yet) is PublicDepositPaymentController.
 */
class DepositPaymentController extends Controller
{
    public function __construct(private readonly StripeDepositPaymentService $deposits) {}

    public function show(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);

        return response()->json(['data' => $this->present($leaseAgreement)]);
    }

    public function charge(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);

        $this->guardChargeable($leaseAgreement);
        $result = $this->deposits->charge($leaseAgreement);

        return response()->json(['data' => [
            'payment' => $result['payment'],
            'requires_action' => $result['requires_action'],
            'client_secret' => $result['client_secret'],
        ]]);
    }

    private function guardChargeable(LeaseAgreement $lease): void
    {
        abort_unless($lease->application?->status === Application::STATUS_WAITING_DEPOSIT, 422, 'This application is not waiting on a deposit right now.');
        abort_unless($lease->contract()->exists(), 422, 'Please sign your lease agreement before paying your deposit.');
        abort_if($lease->application->deposit_received, 422, 'The deposit has already been marked received.');
    }

    private function present(LeaseAgreement $lease): array
    {
        $depositPayment = $lease->payments()->where('type', Payment::TYPE_DEPOSIT)->latest()->first();

        return [
            'amount_due' => $lease->totalDueAtSigning(),
            'breakdown' => [
                'security_deposit' => (float) $lease->security_deposit,
                'tracking_device_fee' => LeaseAgreement::TRACKING_DEVICE_FEE,
                'first_month_payment' => $lease->totalMonthlyPayment(),
            ],
            'chargeable_method' => $lease->autopayChargeablePaymentMethod()['type'] ?? null,
            'deposit_payment' => $depositPayment?->only(['id', 'status', 'method', 'amount', 'paid_date', 'created_at']),
            'already_marked_received' => (bool) $lease->application?->deposit_received,
        ];
    }
}

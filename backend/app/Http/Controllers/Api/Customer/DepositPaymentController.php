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

        $this->guardDepositChargeable($leaseAgreement);
        $result = $this->deposits->chargeDepositOnly($leaseAgreement);

        return response()->json(['data' => [
            'payment' => $result['payment'],
            'requires_action' => $result['requires_action'],
            'client_secret' => $result['client_secret'],
        ]]);
    }

    public function chargeBalance(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);

        $this->guardBalanceChargeable($leaseAgreement);
        $result = $this->deposits->chargePickupBalance($leaseAgreement);

        return response()->json(['data' => [
            'payment' => $result['payment'],
            'requires_action' => $result['requires_action'],
            'client_secret' => $result['client_secret'],
        ]]);
    }

    private function guardDepositChargeable(LeaseAgreement $lease): void
    {
        abort_unless($lease->application?->status === Application::STATUS_WAITING_DEPOSIT, 422, 'This application is not waiting on a deposit right now.');
        abort_unless($lease->contract()->exists(), 422, 'Please sign your lease agreement before paying your deposit.');
        abort_if($lease->application->deposit_received, 422, 'The deposit has already been marked received.');
    }

    /**
     * Deliberately looser than guardDepositChargeable() — the pickup balance
     * can be paid any time after signing, up through waiting_delivery (the
     * customer's whole "whenever I'm ready for pickup" window), not just
     * while still sitting in waiting_deposit.
     */
    private function guardBalanceChargeable(LeaseAgreement $lease): void
    {
        abort_unless($lease->contract()->exists(), 422, 'Please sign your lease agreement first.');
        abort_unless(
            in_array($lease->application?->status, [Application::STATUS_WAITING_DEPOSIT, Application::STATUS_WAITING_DELIVERY], true),
            422,
            'This balance is not payable right now.',
        );
        abort_if($lease->application->pickup_balance_received, 422, 'This balance has already been marked received.');
    }

    private function present(LeaseAgreement $lease): array
    {
        $depositPayment = $lease->payments()->where('type', Payment::TYPE_DEPOSIT)->latest()->first();
        $balancePayment = $lease->payments()->where('type', Payment::TYPE_PICKUP_BALANCE)->latest()->first();

        return [
            'security_deposit' => [
                'amount' => $lease->depositAmountDue(),
                'payment' => $depositPayment?->only(['id', 'status', 'method', 'amount', 'paid_date', 'created_at']),
                'received' => (bool) $lease->application?->deposit_received,
            ],
            'pickup_balance' => [
                'amount' => $lease->pickupBalanceAmountDue(),
                'breakdown' => [
                    'tracking_device_fee' => LeaseAgreement::TRACKING_DEVICE_FEE,
                    'first_month_payment' => $lease->totalMonthlyPayment(),
                ],
                'payment' => $balancePayment?->only(['id', 'status', 'method', 'amount', 'paid_date', 'created_at']),
                'received' => (bool) $lease->application?->pickup_balance_received,
            ],
            'amount_due_full' => $lease->totalDueAtSigning(),
            'chargeable_method' => $lease->autopayChargeablePaymentMethod()['type'] ?? null,
        ];
    }
}

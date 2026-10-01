<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentMethodSigner;
use App\Services\StripeDepositPaymentService;
use Illuminate\Http\Request;

/**
 * The signed-link counterpart to Customer\DepositPaymentController — reached
 * from the same AutoPay signed link a guest-originated customer already used
 * to add their bank account/card (see PaymentMethodSigner; this is a second
 * step on that same page/link, not a new notification journey). Every
 * request here re-validates the signature itself; nothing here trusts
 * Sanctum. Mirrors PublicPaymentMethodController.
 */
class PublicDepositPaymentController extends Controller
{
    public function __construct(private readonly StripeDepositPaymentService $deposits) {}

    public function show(Request $request)
    {
        $customer = $this->resolveSignedCustomer($request);
        $lease = LeaseAgreement::findOrFail($request->integer('lease'));
        abort_unless($lease->customer_id === $customer->id, 404);

        return response()->json(['data' => $this->present($lease)]);
    }

    public function charge(Request $request)
    {
        $customer = $this->resolveSignedCustomer($request);
        $lease = LeaseAgreement::findOrFail($request->integer('lease'));
        abort_unless($lease->customer_id === $customer->id, 404);

        $this->guardChargeable($lease);
        $result = $this->deposits->charge($lease);

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

    private function resolveSignedCustomer(Request $request): User
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'lease' => ['required', 'integer'],
            'hash' => ['required', 'string'],
            'expires' => ['required', 'integer'],
            'signature' => ['required', 'string'],
        ]);

        $customer = User::find($data['id']);
        $linkIsValid = $customer
            && $customer->isCustomer()
            && hash_equals(sha1($customer->email), $data['hash'])
            && PaymentMethodSigner::isValid($data['id'], $data['lease'], $data['hash'], $data['expires'], $data['signature']);

        abort_unless($linkIsValid, 422, 'This link is invalid or has expired.');

        return $customer;
    }
}

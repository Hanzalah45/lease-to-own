<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Models\User;
use App\Services\AutopayAgreementService;
use App\Services\PaymentMethodSigner;
use App\Services\StripePaymentMethodService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The signed-link counterpart to Customer\PaymentMethodController — reached
 * from PaymentMethodsRequestedNotification's emailed link rather than an
 * authenticated session, since a guest-originated customer has no working
 * login yet (see PaymentMethodSigner). Every request here re-validates the
 * signature itself; nothing here trusts Sanctum. Mirrors PublicContractController.
 */
class PublicPaymentMethodController extends Controller
{
    public function __construct(
        private readonly StripePaymentMethodService $paymentMethods,
        private readonly AutopayAgreementService $autopayAgreements,
    ) {}

    public function show(Request $request)
    {
        $customer = $this->resolveSignedCustomer($request);
        $lease = LeaseAgreement::findOrFail($request->integer('lease'));
        abort_unless($lease->customer_id === $customer->id, 404);

        // Guest customers have no session the frontend can read a name/email
        // from for Stripe's required billing_details — the signed link
        // already proves who this is, so it's safe to hand back here.
        return response()->json(['data' => [
            ...$this->present($lease),
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
        ]]);
    }

    public function setupIntent(Request $request)
    {
        $customer = $this->resolveSignedCustomer($request);
        $data = $request->validate(['type' => ['required', Rule::in(['card', 'bank'])]]);

        $lease = LeaseAgreement::findOrFail($request->integer('lease'));
        abort_unless($lease->customer_id === $customer->id, 404);

        $intent = $this->paymentMethods->createSetupIntent($customer, $data['type']);

        return response()->json(['data' => ['client_secret' => $intent->client_secret]]);
    }

    public function confirm(Request $request)
    {
        $customer = $this->resolveSignedCustomer($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(['card', 'bank'])],
            'setup_intent_id' => ['required', 'string'],
        ]);

        $lease = LeaseAgreement::findOrFail($request->integer('lease'));
        abort_unless($lease->customer_id === $customer->id, 404);

        $this->paymentMethods->attachPaymentMethod($lease, $data['type'], $data['setup_intent_id']);

        return response()->json(['data' => $this->present($lease->fresh())]);
    }

    /** A bank account that needs two small test deposits, through the signed link (see Customer\PaymentMethodController::bankPending). */
    public function bankPending(Request $request)
    {
        $lease = $this->signedLease($request);
        $data = $request->validate(['setup_intent_id' => ['required', 'string', 'max:255']]);

        $this->paymentMethods->startPendingBank($lease, $data['setup_intent_id']);

        return response()->json(['data' => $this->present($lease->fresh())]);
    }

    public function bankPendingCheck(Request $request)
    {
        $lease = $this->signedLease($request);

        $result = $this->paymentMethods->checkPendingBank($lease);

        return response()->json(['data' => $this->present($lease->fresh()), 'result' => $result]);
    }

    public function bankPendingCancel(Request $request)
    {
        $lease = $this->signedLease($request);

        $this->paymentMethods->cancelPendingBank($lease);

        return response()->json(['data' => $this->present($lease->fresh())]);
    }

    private function signedLease(Request $request): LeaseAgreement
    {
        $customer = $this->resolveSignedCustomer($request);
        $lease = LeaseAgreement::findOrFail($request->integer('lease'));
        abort_unless($lease->customer_id === $customer->id, 404);

        return $lease;
    }

    /** Switches which already-attached method AutoPay charges first — no new Stripe call needed, unlike confirm(). */
    public function setPrimary(Request $request)
    {
        $customer = $this->resolveSignedCustomer($request);
        $data = $request->validate(['type' => ['required', Rule::in(['card', 'bank'])]]);

        $lease = LeaseAgreement::findOrFail($request->integer('lease'));
        abort_unless($lease->customer_id === $customer->id, 404);

        $column = $data['type'] === 'bank' ? 'stripe_bank_payment_method_id' : 'stripe_card_payment_method_id';
        abort_unless($lease->{$column}, 422, 'Add that payment method before making it your primary.');

        $lease->update(['autopay_primary_method' => $data['type'] === 'bank' ? 'ach' : 'card']);

        return response()->json(['data' => $this->present($lease->fresh())]);
    }

    /** The AutoPay tick box for a guest-originated customer, through the signed link (see Customer\PaymentMethodController::agreeToAutopay). */
    public function agreeToAutopay(Request $request)
    {
        $customer = $this->resolveSignedCustomer($request);
        $data = $request->validate([
            'accepted' => ['accepted'],
            'wording_version' => ['required', 'integer'],
        ]);

        $lease = LeaseAgreement::findOrFail($request->integer('lease'));
        abort_unless($lease->customer_id === $customer->id, 404);

        $this->autopayAgreements->agree($lease, $customer, $request, (int) $data['wording_version'], 'signed_link');

        return response()->json(['data' => $this->present($lease->fresh())]);
    }

    private function present(LeaseAgreement $lease): array
    {
        $pricing = $lease->pricingSummary();

        return [
            'autopay_enabled' => (bool) $lease->autopay_enabled,
            'bank_account_added' => (bool) $lease->stripe_bank_payment_method_id,
            'bank_pending' => $this->paymentMethods->pendingBank($lease),
            // A locked method stays on file but cannot be used; the customer is only told to contact us.
            'bank_locked' => $lease->isMethodLocked('bank'),
            'card_locked' => $lease->isMethodLocked('card'),
            'card_added' => (bool) $lease->stripe_card_payment_method_id,
            'autopay_primary_method' => $lease->autopay_primary_method,
            'autopay_offer' => $this->autopayAgreements->offer($lease),
            // Dual pricing (client, 2026-10-05): what the monthly payment costs by
            // bank vs by card, shown next to each AutoPay choice.
            'monthly_prices' => $pricing['monthly'] + ['card_fee_percent' => $pricing['card_fee_percent']],
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

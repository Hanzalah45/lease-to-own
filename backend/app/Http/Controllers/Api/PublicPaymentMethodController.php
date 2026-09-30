<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Models\User;
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
    public function __construct(private readonly StripePaymentMethodService $paymentMethods) {}

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
            'payment_method_id' => ['required', 'string'],
        ]);

        $lease = LeaseAgreement::findOrFail($request->integer('lease'));
        abort_unless($lease->customer_id === $customer->id, 404);

        $this->paymentMethods->attachPaymentMethod($lease, $data['type'], $data['payment_method_id']);

        return response()->json(['data' => $this->present($lease->fresh())]);
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

    private function present(LeaseAgreement $lease): array
    {
        return [
            'bank_account_added' => (bool) $lease->stripe_bank_payment_method_id,
            'card_added' => (bool) $lease->stripe_card_payment_method_id,
            'autopay_primary_method' => $lease->autopay_primary_method,
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

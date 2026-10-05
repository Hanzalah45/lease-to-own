<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Services\StripePaymentMethodService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AutoPay payment-method setup for an already-logged-in customer — the
 * signed-link counterpart (for a guest-originated customer with no usable
 * password yet) is PublicPaymentMethodController.
 */
class PaymentMethodController extends Controller
{
    public function __construct(private readonly StripePaymentMethodService $paymentMethods) {}

    public function show(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);

        return response()->json(['data' => $this->present($leaseAgreement)]);
    }

    public function setupIntent(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);
        $data = $request->validate(['type' => ['required', Rule::in(['card', 'bank'])]]);

        $intent = $this->paymentMethods->createSetupIntent($request->user(), $data['type']);

        return response()->json(['data' => ['client_secret' => $intent->client_secret]]);
    }

    public function confirm(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);
        $data = $request->validate([
            'type' => ['required', Rule::in(['card', 'bank'])],
            'setup_intent_id' => ['required', 'string'],
        ]);

        $this->paymentMethods->attachPaymentMethod($leaseAgreement, $data['type'], $data['setup_intent_id']);

        return response()->json(['data' => $this->present($leaseAgreement->fresh())]);
    }

    /** Switches which already-attached method AutoPay charges first — no new Stripe call needed, unlike confirm(). */
    public function setPrimary(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);
        $data = $request->validate(['type' => ['required', Rule::in(['card', 'bank'])]]);

        $column = $data['type'] === 'bank' ? 'stripe_bank_payment_method_id' : 'stripe_card_payment_method_id';
        abort_unless($leaseAgreement->{$column}, 422, 'Add that payment method before making it your primary.');

        $leaseAgreement->update(['autopay_primary_method' => $data['type'] === 'bank' ? 'ach' : 'card']);

        return response()->json(['data' => $this->present($leaseAgreement->fresh())]);
    }

    private function present(LeaseAgreement $leaseAgreement): array
    {
        $pricing = $leaseAgreement->pricingSummary();

        return [
            'bank_account_added' => (bool) $leaseAgreement->stripe_bank_payment_method_id,
            'card_added' => (bool) $leaseAgreement->stripe_card_payment_method_id,
            'autopay_primary_method' => $leaseAgreement->autopay_primary_method,
            // Dual pricing (client, 2026-10-05): what the monthly payment costs by
            // bank vs by card, shown next to each AutoPay choice.
            'monthly_prices' => $pricing['monthly'] + ['card_fee_percent' => $pricing['card_fee_percent']],
        ];
    }
}

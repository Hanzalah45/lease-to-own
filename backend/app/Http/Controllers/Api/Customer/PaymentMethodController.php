<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Services\AutopayAgreementService;
use App\Services\AutopayRevocationService;
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
    public function __construct(
        private readonly StripePaymentMethodService $paymentMethods,
        private readonly AutopayAgreementService $autopayAgreements,
        private readonly AutopayRevocationService $revocations,
    ) {}

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

    /**
     * A bank account that needs two small test deposits (client, Joel, 2026-10-19 checklist, section 2): the customer typed the account
     * number, Stripe sent the deposits, and the account waits until the amounts are confirmed.
     */
    public function bankPending(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);
        $data = $request->validate(['setup_intent_id' => ['required', 'string', 'max:255']]);

        $this->paymentMethods->startPendingBank($leaseAgreement, $data['setup_intent_id']);

        return response()->json(['data' => $this->present($leaseAgreement->fresh())]);
    }

    /** "I confirmed the deposits": asks Stripe, and saves the account if it is confirmed. */
    public function bankPendingCheck(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);

        $result = $this->paymentMethods->checkPendingBank($leaseAgreement);

        return response()->json(['data' => $this->present($leaseAgreement->fresh()), 'result' => $result]);
    }

    /** The customer wants to use another account: the one waiting is dropped. */
    public function bankPendingCancel(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);

        $this->paymentMethods->cancelPendingBank($leaseAgreement);

        return response()->json(['data' => $this->present($leaseAgreement->fresh())]);
    }

    /** The customer removes a saved bank account or card (also taken off their Stripe profile). `confirm` must be true: they have read what it means. */
    public function remove(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);
        $data = $request->validate([
            'type' => ['required', Rule::in(['card', 'bank'])],
            'confirm' => ['accepted'],
        ]);

        $this->paymentMethods->removeForCustomer($leaseAgreement, $data['type'], $request->user());

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

    /**
     * The AutoPay tick box: the customer agrees to the words they were shown, and AutoPay turns on (client, Joel, 2026-10-20).
     * `accepted` must be true, and `wording_version` the version of the words that was on screen.
     */
    public function agreeToAutopay(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);
        $data = $request->validate([
            'accepted' => ['accepted'],
            'wording_version' => ['required', 'integer'],
        ]);

        $this->autopayAgreements->agree($leaseAgreement, $request->user(), $request, (int) $data['wording_version'], 'portal');

        return response()->json(['data' => $this->present($leaseAgreement->fresh())]);
    }

    /**
     * Turns AutoPay off at the customer's request (client, Joel, 2026-10-19 checklist, section 5). The AutoPay authorization asks for
     * three business days' notice before the next scheduled payment, so AutoPay keeps charging until then and is switched off by the
     * system on that day. `confirm` must be true: the customer has read what it means.
     */
    public function revokeAutopay(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);
        $request->validate(['confirm' => ['accepted']]);

        $this->revocations->request($leaseAgreement, $request->user(), $request, 'portal');

        return response()->json(['data' => $this->present($leaseAgreement->fresh())]);
    }

    /** The customer keeps AutoPay on after asking to turn it off, any time before the day it would have stopped. */
    public function keepAutopay(Request $request, LeaseAgreement $leaseAgreement)
    {
        abort_unless($leaseAgreement->customer_id === $request->user()->id, 404);

        $this->revocations->withdraw($leaseAgreement, $request->user());

        return response()->json(['data' => $this->present($leaseAgreement->fresh())]);
    }

    private function present(LeaseAgreement $leaseAgreement): array
    {
        $pricing = $leaseAgreement->pricingSummary();

        return [
            // A lease with AutoPay off still saves a bank account or card (it is what the customer pays with), so the page
            // needs to know which words to use (client, Joel, 2026-10-16).
            'autopay_enabled' => (bool) $leaseAgreement->autopay_enabled,
            'bank_account_added' => (bool) $leaseAgreement->stripe_bank_payment_method_id,
            // A bank account typed in by hand that is waiting for its two small deposits to be confirmed.
            'bank_pending' => $this->paymentMethods->pendingBank($leaseAgreement),
            'card_added' => (bool) $leaseAgreement->stripe_card_payment_method_id,
            // A locked method stays on file but cannot be used. The customer is told to contact us, never why.
            'bank_locked' => $leaseAgreement->isMethodLocked('bank'),
            'card_locked' => $leaseAgreement->isMethodLocked('card'),
            'autopay_primary_method' => $leaseAgreement->autopay_primary_method,
            // The AutoPay tick box and the words next to it; null when it is not offered (AutoPay already on, or no saved method yet).
            'autopay_offer' => $this->autopayAgreements->offer($leaseAgreement),
            // Turning AutoPay off: whether the customer can ask, and a request that is waiting for its day (three business days after it was made).
            'autopay_revocation' => ($pending = AutopayRevocationService::pending($leaseAgreement))
                ? ['requested_at' => $pending->requested_at, 'effective_on' => $pending->effective_on->toDateString()]
                : null,
            'can_revoke_autopay' => AutopayRevocationService::blocker($leaseAgreement) === null,
            'autopay_notice_days' => AutopayRevocationService::NOTICE_BUSINESS_DAYS,
            // Dual pricing (client, 2026-10-05): what the monthly payment costs by
            // bank vs by card, shown next to each AutoPay choice.
            'monthly_prices' => $pricing['monthly'] + ['card_fee_percent' => $pricing['card_fee_percent']],
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaseAgreement;
use App\Notifications\PaymentMethodsRequestedNotification;
use App\Services\PaymentMethodSigner;
use App\Services\StripePaymentMethodService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-facing AutoPay payment-method management (client, 2026-10-01): an
 * admin can clear either payment method on a lease (expired card, closed
 * bank account, etc.) so the customer can be sent a fresh link to re-add it —
 * mirrors resendContractSigningLink()'s "resend the signed link" pattern.
 */
class PaymentMethodController extends Controller
{
    public function __construct(private readonly StripePaymentMethodService $paymentMethods) {}

    public function clear(Request $request, LeaseAgreement $leaseAgreement)
    {
        $data = $request->validate(['type' => ['required', Rule::in(['card', 'bank'])]]);

        $previousId = $data['type'] === 'bank' ? $leaseAgreement->stripe_bank_payment_method_id : $leaseAgreement->stripe_card_payment_method_id;

        $leaseAgreement->update([
            $data['type'] === 'bank' ? 'stripe_bank_payment_method_id' : 'stripe_card_payment_method_id' => null,
            // Clearing the method the customer had chosen as primary leaves
            // nothing selected until they pick again.
            ...($leaseAgreement->autopay_primary_method === ($data['type'] === 'bank' ? 'ach' : 'card')
                ? ['autopay_primary_method' => null]
                : []),
        ]);

        // "Delete the card on file" also takes it off the customer's Stripe profile (client, Joel, 2026-10-16), so the
        // confirmation he asked for is true: it can no longer be charged by anyone. A debit already on its way keeps going.
        if ($previousId) {
            $this->paymentMethods->detach($previousId);
        }

        // Guest-originated customers (no usable password yet) need a fresh
        // signed link — an already-activated customer just goes back to
        // their own portal, same fork resendContractSigningLink() uses.
        if ($leaseAgreement->customer->status === 'pending') {
            $leaseAgreement->customer->notify(
                new PaymentMethodsRequestedNotification(PaymentMethodSigner::urlFor($leaseAgreement->customer, $leaseAgreement)),
            );
        }

        return response()->json(['data' => [
            'bank_account_added' => (bool) $leaseAgreement->stripe_bank_payment_method_id,
            'card_added' => (bool) $leaseAgreement->stripe_card_payment_method_id,
            'autopay_primary_method' => $leaseAgreement->autopay_primary_method,
        ]]);
    }
}

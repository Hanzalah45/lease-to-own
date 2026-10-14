<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Services\StripePaymentMethodService;
use Illuminate\Http\Request;

/**
 * Staff add the customer's card to their AutoPay (client, Joel, 2026-10-14):
 * a customer who cannot manage the card form on their own is helped by phone,
 * and Joel wanted to enter it himself as an admin. The card is typed into
 * Stripe's own form in the admin's browser, so the number goes straight to
 * Stripe and never reaches this server; only the SetupIntent id comes back,
 * and the same checks as the customer's own flow decide whether it is saved
 * (StripePaymentMethodService::attachPaymentMethod). Cards only: a bank
 * account needs the customer's own bank login.
 */
class LeaseCardController extends Controller
{
    public function __construct(private readonly StripePaymentMethodService $paymentMethods) {}

    public function setupIntent(LeaseAgreement $leaseAgreement)
    {
        $this->assertCardCanBeAdded($leaseAgreement);

        $intent = $this->paymentMethods->createSetupIntent($leaseAgreement->customer, 'card');

        return response()->json(['data' => ['client_secret' => $intent->client_secret]]);
    }

    public function store(Request $request, LeaseAgreement $leaseAgreement)
    {
        $data = $request->validate([
            'setup_intent_id' => ['required', 'string', 'max:255'],
            'customer_agreed' => ['accepted'],
        ]);
        $this->assertCardCanBeAdded($leaseAgreement);

        $card = $this->paymentMethods->attachPaymentMethod($leaseAgreement, 'card', $data['setup_intent_id'])->card;

        $admin = $request->user();
        $leaseAgreement->application?->dealerNotes()->create([
            'author_user_id' => $admin->id,
            'text' => sprintf(
                'Card added to AutoPay on the customer\'s behalf: %s ending %s. Entered by %s after the customer agreed to it being charged for their monthly payments.',
                ucfirst((string) ($card?->brand ?? 'card')),
                $card?->last4 ?? '????',
                $admin->name,
            ),
        ]);

        return response()->json(['data' => [
            'card' => ['brand' => $card?->brand, 'last4' => $card?->last4],
        ]]);
    }

    private function assertCardCanBeAdded(LeaseAgreement $lease): void
    {
        abort_unless($lease->autopay_enabled, 422, 'AutoPay is off for this lease.');
        abort_if($lease->stripe_card_payment_method_id, 422, 'A card is already saved for this lease.');
        abort_unless(
            in_array($lease->application?->status, [Application::STATUS_WAITING_DEPOSIT, Application::STATUS_WAITING_DELIVERY, Application::STATUS_FINISHED], true),
            422,
            'A card can only be added once the lease is at the deposit stage or later.',
        );
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Services\StripePaymentMethodService;
use Illuminate\Http\Request;

/**
 * Staff see the customer's AutoPay methods and add or change the card for them
 * (client, Joel, 2026-10-14/15): a customer who cannot manage the card form on
 * their own, or who changes cards, is helped by phone, and Joel wanted to do it
 * himself as an admin. The card is typed into Stripe's own form in the admin's
 * browser, so the number goes straight to Stripe and never reaches this server;
 * only the SetupIntent id comes back, and the same checks as the customer's own
 * flow decide whether it is saved (StripePaymentMethodService::attachPaymentMethod).
 * Cards only: a bank account needs the customer's own bank login. Staff never
 * see more than the brand, last four digits and expiry.
 */
class LeaseCardController extends Controller
{
    public function __construct(private readonly StripePaymentMethodService $paymentMethods) {}

    /** The bank account and card AutoPay would use, as Stripe describes them (no full numbers). */
    public function show(LeaseAgreement $leaseAgreement)
    {
        $describe = fn (?string $id) => $id ? ($this->paymentMethods->describe($id) ?? ['unavailable' => true]) : null;

        return response()->json(['data' => [
            'autopay_enabled' => (bool) $leaseAgreement->autopay_enabled,
            'primary' => $leaseAgreement->autopay_primary_method,
            'bank' => $describe($leaseAgreement->stripe_bank_payment_method_id),
            'card' => $describe($leaseAgreement->stripe_card_payment_method_id),
        ]]);
    }

    public function setupIntent(LeaseAgreement $leaseAgreement)
    {
        $this->assertCardCanBeSaved($leaseAgreement);

        $intent = $this->paymentMethods->createSetupIntent($leaseAgreement->customer, 'card');

        return response()->json(['data' => ['client_secret' => $intent->client_secret]]);
    }

    /** Saves a new card, or replaces the one on file (the old one is taken off the customer's Stripe profile). */
    public function store(Request $request, LeaseAgreement $leaseAgreement)
    {
        $data = $request->validate([
            'setup_intent_id' => ['required', 'string', 'max:255'],
            'customer_agreed' => ['accepted'],
        ]);
        $this->assertCardCanBeSaved($leaseAgreement);

        $previousId = $leaseAgreement->stripe_card_payment_method_id;
        $previous = $previousId ? $this->paymentMethods->describe($previousId) : null;

        $paymentMethod = $this->paymentMethods->attachPaymentMethod($leaseAgreement, 'card', $data['setup_intent_id']);
        $card = $paymentMethod->card;

        if ($previousId && $previousId !== $paymentMethod->id) {
            $this->paymentMethods->detach($previousId);
        }

        $admin = $request->user();
        $new = sprintf('%s ending %s', ucfirst((string) ($card?->brand ?? 'card')), $card?->last4 ?? '????');
        $leaseAgreement->application?->dealerNotes()->create([
            'author_user_id' => $admin->id,
            'text' => $previousId
                ? sprintf(
                    'Card changed on the customer\'s behalf: %s replaced by %s. Entered by %s after the customer agreed to the new card being charged for their monthly payments.',
                    isset($previous['brand']) ? sprintf('%s ending %s', ucfirst((string) $previous['brand']), $previous['last4'] ?? '????') : 'the previous card',
                    $new,
                    $admin->name,
                )
                : sprintf(
                    'Card added to AutoPay on the customer\'s behalf: %s. Entered by %s after the customer agreed to it being charged for their monthly payments.',
                    $new,
                    $admin->name,
                ),
        ]);

        return response()->json(['data' => [
            'card' => ['brand' => $card?->brand, 'last4' => $card?->last4],
            'replaced' => (bool) $previousId,
        ]]);
    }

    private function assertCardCanBeSaved(LeaseAgreement $lease): void
    {
        abort_unless($lease->autopay_enabled, 422, 'AutoPay is off for this lease.');
        abort_unless(
            in_array($lease->application?->status, [Application::STATUS_WAITING_DEPOSIT, Application::STATUS_WAITING_DELIVERY, Application::STATUS_FINISHED], true),
            422,
            'A card can only be saved once the lease is at the deposit stage or later.',
        );
    }
}

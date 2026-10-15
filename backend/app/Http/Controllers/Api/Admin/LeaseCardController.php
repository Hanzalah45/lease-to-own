<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Services\PaymentMethodSigner;
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
 * Staff only ever enter cards: a bank account needs the customer's own bank
 * login, so for a bank account staff open a page on the office computer and the
 * customer links it themselves (bankLink). Staff never see more than the brand,
 * last four digits and expiry.
 */
class LeaseCardController extends Controller
{
    /** How long the page opened on the office computer keeps working. */
    private const OFFICE_LINK_MINUTES = 30;

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

        // Saving the new card also takes the replaced one off the customer's Stripe profile.
        $paymentMethod = $this->paymentMethods->attachPaymentMethod($leaseAgreement, 'card', $data['setup_intent_id']);
        $card = $paymentMethod->card;

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

    /**
     * A page for the customer to link their own bank account on the office computer (client, Joel, 2026-10-16:
     * "Can't send the customer a payment link? Upload the customer's card or bank account information here").
     * It is the same signed page the emailed link opens, so the customer logs in to their own bank in Stripe's
     * window and staff never see the login; unlike the emailed link it only works for a short while. Nothing is
     * saved here, the customer saves it on that page.
     */
    public function bankLink(Request $request, LeaseAgreement $leaseAgreement)
    {
        $this->assertMethodCanBeSaved($leaseAgreement, 'A bank account');

        $admin = $request->user();
        $leaseAgreement->application?->dealerNotes()->create([
            'author_user_id' => $admin->id,
            'text' => sprintf('A page to link a bank account was opened on the office computer by %s so the customer could link their own account.', $admin->name),
        ]);

        return response()->json(['data' => [
            'url' => PaymentMethodSigner::urlFor($leaseAgreement->customer, $leaseAgreement, self::OFFICE_LINK_MINUTES).'&office=1',
            'expires_in_minutes' => self::OFFICE_LINK_MINUTES,
        ]]);
    }

    private function assertCardCanBeSaved(LeaseAgreement $lease): void
    {
        $this->assertMethodCanBeSaved($lease, 'A card');
    }

    private function assertMethodCanBeSaved(LeaseAgreement $lease, string $what): void
    {
        // Not tied to AutoPay: a saved card is also what a customer pays with from their own screen (2026-10-15,
        // a customer with AutoPay off added the wrong card and neither she nor staff could change it).
        abort_unless(
            in_array($lease->application?->status, [Application::STATUS_WAITING_DEPOSIT, Application::STATUS_WAITING_DELIVERY, Application::STATUS_FINISHED], true),
            422,
            "{$what} can only be saved once the lease is at the deposit stage or later.",
        );
    }
}

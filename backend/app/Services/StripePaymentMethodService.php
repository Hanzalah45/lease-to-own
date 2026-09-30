<?php

namespace App\Services;

use App\Models\LeaseAgreement;
use App\Models\RiskRedFlag;
use App\Models\User;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\StripeClient;

/**
 * AutoPay payment-method collection (client, 2026-10-01): a customer adds a
 * bank account (via Stripe Financial Connections) and a backup card at the
 * waiting_deposit stage, and picks which one AutoPay charges first — the
 * other serves as the automatic fallback if the chosen one fails (see
 * StripeWebhookController for the failure side of that). Deliberately
 * separate from the Plaid bank-verification flow at in_verification, which
 * stays identity/risk-only — see RiskScoringService::recordBankVerification().
 */
class StripePaymentMethodService
{
    private StripeClient $stripe;

    public function __construct(?StripeClient $stripe = null)
    {
        $this->stripe = $stripe ?? new StripeClient(config('services.stripe.secret'));
    }

    /** Creates the customer's Stripe Customer object the first time they set up a payment method, reused after. */
    public function ensureStripeCustomer(User $customer): string
    {
        $profile = $customer->customerProfile()->firstOrCreate([]);

        if ($profile->stripe_customer_id) {
            return $profile->stripe_customer_id;
        }

        $stripeCustomer = $this->stripe->customers->create([
            'name' => $customer->name,
            'email' => $customer->email,
        ]);

        $profile->update(['stripe_customer_id' => $stripeCustomer->id]);

        return $stripeCustomer->id;
    }

    /** @param  string  $type  'card' or 'bank' */
    public function createSetupIntent(User $customer, string $type): SetupIntent
    {
        $stripeCustomerId = $this->ensureStripeCustomer($customer);

        return $this->stripe->setupIntents->create([
            'customer' => $stripeCustomerId,
            'payment_method_types' => [$type === 'bank' ? 'us_bank_account' : 'card'],
            ...($type === 'bank' ? [
                'payment_method_options' => [
                    'us_bank_account' => [
                        'financial_connections' => ['permissions' => ['payment_method']],
                    ],
                ],
            ] : []),
        ]);
    }

    /**
     * Attaches a confirmed PaymentMethod to the lease. For a bank account,
     * also checks it against the Plaid-verified one on file and flags a
     * mismatch — Joel, 2026-10-01.
     */
    public function attachPaymentMethod(LeaseAgreement $lease, string $type, string $paymentMethodId): void
    {
        $paymentMethod = $this->stripe->paymentMethods->retrieve($paymentMethodId);

        $lease->update([
            $type === 'bank' ? 'stripe_bank_payment_method_id' : 'stripe_card_payment_method_id' => $paymentMethodId,
        ]);

        if ($type === 'bank') {
            $this->flagBankAccountMismatchIfNeeded($lease, $paymentMethod);
        }
    }

    private function flagBankAccountMismatchIfNeeded(LeaseAgreement $lease, PaymentMethod $paymentMethod): void
    {
        $profile = $lease->customer->customerProfile;
        $verifiedMask = $profile?->plaid_verified_bank_mask;

        if (! $verifiedMask) {
            // No Plaid-verified account on file to compare against (e.g. the
            // customer never completed bank verification) — nothing to flag.
            return;
        }

        $linkedMask = $paymentMethod->us_bank_account?->last4;

        if ($linkedMask && $linkedMask !== $verifiedMask) {
            RiskRedFlagger::flag(
                $lease->customer_id,
                RiskRedFlag::TYPE_BANK_ACCOUNT_CHANGE,
                sprintf(
                    'AutoPay bank account (ending %s) does not match the bank account verified earlier (ending %s).',
                    $linkedMask,
                    $verifiedMask,
                ),
            );
        }
    }
}

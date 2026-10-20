<?php

namespace App\Services;

use App\Models\LeaseAgreement;
use App\Models\PaymentAttempt;
use App\Models\PaymentPart;
use App\Models\RiskRedFlag;
use App\Models\User;
use App\Notifications\BankAccountVerificationNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
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
            // Explicit (it is also Stripe's default): the saved method is
            // charged later with the customer absent — see AutopayCharger.
            'usage' => 'off_session',
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
     * Attaches the PaymentMethod from a COMPLETED SetupIntent to the lease.
     * AutoPay charges this method later with the customer absent
     * (off-session), so only a method Stripe has fully set up for the
     * customer is acceptable: the SetupIntent must have succeeded (a bank
     * account still waiting on microdeposit verification, or a card needing
     * authentication, would fail every future charge) and must belong to this
     * customer's Stripe profile. For a bank account, also checks it against
     * the Plaid-verified one on file and flags a mismatch — Joel, 2026-10-01.
     */
    public function attachPaymentMethod(LeaseAgreement $lease, string $type, string $setupIntentId, bool $system = false): PaymentMethod
    {
        $stripeCustomerId = $lease->customer->customerProfile?->stripe_customer_id;
        $setupIntent = $this->stripe->setupIntents->retrieve($setupIntentId);

        abort_unless(
            $stripeCustomerId && $setupIntent->customer === $stripeCustomerId,
            422,
            'That payment method does not belong to this account.',
        );
        abort_unless(
            $setupIntent->status === 'succeeded',
            422,
            $type === 'bank'
                ? 'Your bank account could not be verified instantly. Please try a different bank account or add a card.'
                : 'Your card could not be verified. Please try again or use a different card.',
        );

        $paymentMethodId = is_string($setupIntent->payment_method) ? $setupIntent->payment_method : $setupIntent->payment_method?->id;
        abort_unless($paymentMethodId, 422, 'No payment method was saved. Please try again.');

        $paymentMethod = $this->stripe->paymentMethods->retrieve($paymentMethodId);
        abort_unless(
            $paymentMethod->type === ($type === 'bank' ? 'us_bank_account' : 'card'),
            422,
            'That is not the type of payment method you selected.',
        );

        $column = $type === 'bank' ? 'stripe_bank_payment_method_id' : 'stripe_card_payment_method_id';
        $previousId = $lease->{$column};

        $lease->update([$column => $paymentMethodId]);

        // The audit trail, from whoever is signed in; a guest on a signed link is the customer themselves.
        $described = $type === 'bank'
            ? trim(($paymentMethod->us_bank_account?->bank_name ?? 'Bank account').' ending '.($paymentMethod->us_bank_account?->last4 ?? '????'))
            : trim(ucfirst((string) ($paymentMethod->card?->brand ?? 'card')).' ending '.($paymentMethod->card?->last4 ?? '????'));
        AuditLog::record($previousId ? 'method.replaced' : 'method.saved', sprintf('%s %s: %s.', $type === 'bank' ? 'Bank account' : 'Card', $previousId ? 'replaced' : 'saved', $described), [
            'lease' => $lease, 'method' => $type === 'bank' ? 'ach' : 'card', 'actor' => Auth::user() ?? $lease->customer, 'system' => $system,
        ]);

        if ($type === 'bank') {
            $this->flagBankAccountMismatchIfNeeded($lease, $paymentMethod);
        }

        // A method that was replaced (a customer or staff changed their card) comes off the customer's Stripe
        // profile, so the old one can no longer be charged by mistake.
        if ($previousId && $previousId !== $paymentMethodId) {
            $this->detach($previousId);
        }

        return $paymentMethod;
    }

    // ---- a customer takes a saved method off ----

    /**
     * The customer removes a saved bank account or card (client, Joel, 2026-10-19 checklist, section 2: "remove payment method"). It is
     * also taken off their Stripe profile, so it can never be charged again. Refused, in words for the customer, when that would break
     * something: a method that is locked (staff handle it), a payment still being processed with it, or the only method of a lease whose
     * AutoPay is on (AutoPay would then have nothing to charge).
     */
    public function removeForCustomer(LeaseAgreement $lease, string $type, User $by): void
    {
        $label = $type === 'bank' ? 'bank account' : 'card';
        $column = $type === 'bank' ? 'stripe_bank_payment_method_id' : 'stripe_card_payment_method_id';
        $otherColumn = $type === 'bank' ? 'stripe_card_payment_method_id' : 'stripe_bank_payment_method_id';
        $methodId = $lease->{$column};

        abort_unless($methodId, 422, "There is no {$label} saved to remove.");
        abort_if($lease->isMethodLocked($type), 422, "This {$label} cannot be removed here. Please contact us.");
        abort_if($this->hasPaymentOnItsWay($lease, $type), 422, "A payment is still being processed with this {$label}. You can remove it once that payment clears.");
        abort_if(
            $lease->autopay_enabled && ! $lease->{$otherColumn},
            422,
            "AutoPay is on and this {$label} is the only payment method you have saved. Add another one first, or turn AutoPay off.",
        );

        $primary = $type === 'bank' ? 'ach' : 'card';
        $lease->update([
            $column => null,
            // The one that is left becomes the one AutoPay charges first; with none left nothing is chosen.
            ...($lease->autopay_primary_method === $primary ? ['autopay_primary_method' => $lease->{$otherColumn} ? ($type === 'bank' ? 'card' : 'ach') : null] : []),
        ]);

        AuditLog::record('method.removed', sprintf('The customer removed their saved %s.', $label), [
            'lease' => $lease, 'method' => $primary, 'actor' => $by,
        ]);
        $this->detach($methodId);
    }

    /** True when a charge made with this kind of method has not settled yet (a bank debit takes days). */
    private function hasPaymentOnItsWay(LeaseAgreement $lease, string $type): bool
    {
        $mine = fn ($q) => $q->where('lease_agreement_id', $lease->id);

        return PaymentAttempt::whereHas('payment', $mine)
            ->where('method', $type)
            ->whereIn('status', [PaymentAttempt::STATUS_INITIATED, PaymentAttempt::STATUS_PROCESSING])
            ->exists()
            || PaymentPart::whereHas('payment', $mine)
                ->where('method', $type === 'bank' ? 'ach' : 'card')
                ->where('status', PaymentPart::STATUS_PENDING)
                ->exists();
    }

    // ---- a bank account that needs two small deposits ----

    /**
     * A bank account Stripe could not confirm instantly (the customer typed the account number instead of logging in to their bank):
     * Stripe sends two small deposits, or one with a code, that arrive in one to two business days, and the customer confirms them on
     * Stripe's own page. Until then the account cannot be charged, so it waits here, apart from the account the lease charges, and the
     * customer is told what to do. Starting again drops the account that was waiting before.
     *
     * @return array<string, mixed> what the customer is shown (see pendingBank)
     */
    public function startPendingBank(LeaseAgreement $lease, string $setupIntentId): array
    {
        $intent = $this->stripe->setupIntents->retrieve($setupIntentId);
        $stripeCustomerId = $lease->customer->customerProfile?->stripe_customer_id;
        abort_unless($stripeCustomerId && $intent->customer === $stripeCustomerId, 422, 'That bank account does not belong to this account.');

        $action = $intent->next_action;
        abort_unless(
            $intent->status === 'requires_action' && ($action->type ?? null) === 'verify_with_microdeposits',
            422,
            'That bank account is not waiting for small deposits.',
        );
        $verify = $action->verify_with_microdeposits;

        // Only one account can be waiting: the one before it is dropped.
        $previousId = $lease->bank_pending_setup_intent_id;
        if ($previousId && $previousId !== $intent->id) {
            $this->cancelSetupIntentQuietly($previousId);
        }

        $arrival = $verify->arrival_date ?? null;
        $lease->update([
            'bank_pending_setup_intent_id' => $intent->id,
            'bank_pending_since' => now(),
            // Stripe gives the arrival day as midnight in New York.
            'bank_pending_arrival' => $arrival ? Carbon::createFromTimestamp((int) $arrival, 'America/New_York')->toDateString() : null,
            'bank_pending_verify_url' => isset($verify->hosted_verification_url) ? Str::limit((string) $verify->hosted_verification_url, 500, '') : null,
            'bank_pending_kind' => isset($verify->microdeposit_type) ? Str::limit((string) $verify->microdeposit_type, 20, '') : 'amounts',
        ]);

        AuditLog::record('method.pending', 'A bank account was added and is waiting for its two small deposits to be confirmed.', [
            'lease' => $lease, 'method' => 'ach', 'actor' => Auth::user() ?? $lease->customer,
        ]);
        $this->tell($lease, BankAccountVerificationNotification::WAITING);

        return $this->pendingBank($lease->fresh());
    }

    /**
     * What the customer is shown while a bank account waits for its deposits: since when, when they should have arrived, and where to
     * confirm them. Null when no account is waiting.
     *
     * @return array<string, mixed>|null
     */
    public function pendingBank(LeaseAgreement $lease): ?array
    {
        if (! $lease->bank_pending_setup_intent_id) {
            return null;
        }

        return [
            'since' => $lease->bank_pending_since,
            'arrives_by' => $lease->bank_pending_arrival?->toDateString(),
            'kind' => $lease->bank_pending_kind ?? 'amounts',
            'verify_url' => $lease->bank_pending_verify_url,
        ];
    }

    /**
     * The customer says they confirmed the deposits: asks Stripe where the account stands. Verified saves it to the lease; still
     * waiting changes nothing; failed or cancelled clears it so the customer can add an account again.
     *
     * @return array{state: string, message: string}
     */
    public function checkPendingBank(LeaseAgreement $lease): array
    {
        $id = $lease->bank_pending_setup_intent_id;
        abort_unless($id, 422, 'No bank account is waiting to be confirmed.');

        $intent = $this->stripe->setupIntents->retrieve($id);

        if ($intent->status === 'succeeded') {
            $this->finishPendingBank($lease, $intent->id, false);

            return ['state' => 'verified', 'message' => 'Your bank account is confirmed and saved.'];
        }
        if ($intent->status === 'requires_action') {
            return ['state' => 'waiting', 'message' => 'Stripe has not received the confirmation yet. Open the confirmation page, enter what your bank statement shows, then try again.'];
        }

        $this->failPendingBank($lease, $id);

        return ['state' => 'failed', 'message' => 'Your bank account could not be confirmed. Please add it again, or use a different bank account or a card.'];
    }

    /** The customer wants to use another account instead: the one waiting is dropped. */
    public function cancelPendingBank(LeaseAgreement $lease): void
    {
        $id = $lease->bank_pending_setup_intent_id;
        abort_unless($id, 422, 'No bank account is waiting to be confirmed.');

        $this->clearPending($lease, $id);
        $this->cancelSetupIntentQuietly($id);
        AuditLog::record('method.pending_cancelled', 'The bank account that was waiting for its small deposits was dropped.', [
            'lease' => $lease, 'method' => 'ach', 'actor' => Auth::user() ?? $lease->customer,
        ]);
    }

    /**
     * Stripe tells us how a SetupIntent ended (setup_intent.succeeded, setup_intent.setup_failed, setup_intent.canceled). Only one that
     * belongs to a bank account waiting for its deposits matters here: every card and instant bank setup raises the same events and is
     * ignored. True when the event was one of ours.
     */
    public function applySetupIntentEvent(SetupIntent $intent, bool $succeeded): bool
    {
        $lease = LeaseAgreement::where('bank_pending_setup_intent_id', $intent->id)->first();
        if (! $lease) {
            return false;
        }

        if ($succeeded) {
            $this->finishPendingBank($lease, $intent->id, true);
        } else {
            $this->failPendingBank($lease, $intent->id);
        }

        return true;
    }

    /** The account was confirmed: it is saved to the lease like any other bank account, and the customer is told. */
    private function finishPendingBank(LeaseAgreement $lease, string $setupIntentId, bool $system): void
    {
        // Whoever gets here first (the customer's button or Stripe's event) does the work; the other finds nothing waiting.
        if (! $this->clearPending($lease, $setupIntentId)) {
            return;
        }

        $this->attachPaymentMethod($lease->fresh(), 'bank', $setupIntentId, $system);
        $this->tell($lease->fresh(), BankAccountVerificationNotification::VERIFIED);
    }

    /** The account could not be confirmed (wrong amounts too many times, or the days ran out): cleared, and the customer is told. */
    private function failPendingBank(LeaseAgreement $lease, string $setupIntentId): void
    {
        if (! $this->clearPending($lease, $setupIntentId)) {
            return;
        }

        AuditLog::record('method.pending_failed', 'The bank account that was waiting for its small deposits could not be confirmed.', [
            'lease' => $lease, 'method' => 'ach', 'system' => true,
        ]);
        $this->tell($lease->fresh(), BankAccountVerificationNotification::FAILED);
    }

    /** Clears the waiting account if it is still the one named; false when something else got there first. */
    private function clearPending(LeaseAgreement $lease, string $setupIntentId): bool
    {
        $changed = LeaseAgreement::whereKey($lease->id)->where('bank_pending_setup_intent_id', $setupIntentId)->update([
            'bank_pending_setup_intent_id' => null,
            'bank_pending_since' => null,
            'bank_pending_arrival' => null,
            'bank_pending_verify_url' => null,
            'bank_pending_kind' => null,
        ]);
        $lease->refresh();

        return $changed === 1;
    }

    private function cancelSetupIntentQuietly(string $setupIntentId): void
    {
        try {
            $this->stripe->setupIntents->cancel($setupIntentId);
        } catch (ApiErrorException $e) {
            Log::warning('Could not cancel a bank setup that was replaced.', ['setup_intent' => $setupIntentId, 'error' => $e->getMessage()]);
        }
    }

    /** A mail problem never undoes what happened to the account. */
    private function tell(LeaseAgreement $lease, string $state): void
    {
        try {
            $lease->loadMissing('customer');
            $lease->customer?->notify(new BankAccountVerificationNotification($state, $lease->id, $state === BankAccountVerificationNotification::WAITING ? $this->pendingBank($lease) : null));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * What staff may see about a saved method: brand, last four and expiry of a card, bank name and last four
     * of a bank account. Never the full number. Null when Stripe cannot be reached, so a page that only wants
     * to show it is not broken by that.
     *
     * @return array<string, mixed>|null
     */
    public function describe(string $paymentMethodId): ?array
    {
        try {
            $paymentMethod = $this->stripe->paymentMethods->retrieve($paymentMethodId);
        } catch (ApiErrorException $e) {
            Log::warning('Could not read a saved payment method from Stripe.', ['payment_method' => $paymentMethodId, 'error' => $e->getMessage()]);

            return null;
        }

        return match ($paymentMethod->type) {
            'card' => [
                'type' => 'card',
                'brand' => $paymentMethod->card?->brand,
                'last4' => $paymentMethod->card?->last4,
                'exp_month' => $paymentMethod->card?->exp_month,
                'exp_year' => $paymentMethod->card?->exp_year,
                'funding' => $paymentMethod->card?->funding,
            ],
            'us_bank_account' => [
                'type' => 'bank',
                'bank_name' => $paymentMethod->us_bank_account?->bank_name,
                'last4' => $paymentMethod->us_bank_account?->last4,
                'account_type' => $paymentMethod->us_bank_account?->account_type,
            ],
            default => ['type' => $paymentMethod->type],
        };
    }

    /** Takes a replaced method off the customer's Stripe profile so it cannot be charged again. A failure here is only logged: the lease already points at the new one. */
    public function detach(string $paymentMethodId): void
    {
        try {
            $this->stripe->paymentMethods->detach($paymentMethodId);
        } catch (ApiErrorException $e) {
            Log::warning('Could not detach a replaced payment method from Stripe.', ['payment_method' => $paymentMethodId, 'error' => $e->getMessage()]);
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

<?php

namespace App\Console\Commands;

use App\Models\CustomerProfile;
use App\Models\LeaseAgreement;
use Illuminate\Console\Command;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

/**
 * Clears Stripe references that do not exist in the Stripe mode the app is
 * configured for. When production moves from test keys to live keys (client,
 * Joel, 2026-10-06), every customer id and saved payment method created in
 * test mode stops existing: a live key answers "No such customer" for them, so
 * AutoPay setup and charging would fail. Each saved reference is looked up and
 * only the ones Stripe says are missing are cleared, so it is safe to run at
 * any time: it never clears anything that exists in the current mode.
 *
 * Without --apply nothing is saved.
 */
class PruneMissingStripeReferences extends Command
{
    protected $signature = 'stripe:prune-missing-references
        {--apply : Clear the missing references. Without this it only lists them}';

    protected $description = 'Clear saved Stripe customer and payment method ids that do not exist in the configured Stripe mode';

    public function handle(): int
    {
        $secret = (string) config('services.stripe.secret');
        $mode = str_starts_with($secret, 'sk_live_') ? 'LIVE' : (str_starts_with($secret, 'sk_test_') ? 'TEST' : 'unknown');
        $this->line("The configured Stripe key is a {$mode} key.");
        if ($mode === 'unknown') {
            $this->error('STRIPE_SECRET is missing or not a Stripe secret key.');

            return self::FAILURE;
        }

        $stripe = new StripeClient($secret);
        $apply = (bool) $this->option('apply');
        $cleared = 0;

        foreach (CustomerProfile::whereNotNull('stripe_customer_id')->get() as $profile) {
            if ($this->exists(fn () => $stripe->customers->retrieve($profile->stripe_customer_id))) {
                continue;
            }
            $this->line("Customer profile #{$profile->id}: Stripe customer {$profile->stripe_customer_id} does not exist in {$mode} mode.");
            $apply && $profile->update(['stripe_customer_id' => null]);
            $cleared++;
        }

        $leases = LeaseAgreement::query()
            ->where(fn ($q) => $q->whereNotNull('stripe_bank_payment_method_id')->orWhereNotNull('stripe_card_payment_method_id'))
            ->get();
        foreach ($leases as $lease) {
            $changes = [];
            foreach (['stripe_bank_payment_method_id' => 'ach', 'stripe_card_payment_method_id' => 'card'] as $column => $primaryName) {
                $id = $lease->{$column};
                if (! $id || $this->exists(fn () => $stripe->paymentMethods->retrieve($id))) {
                    continue;
                }
                $this->line("Lease #{$lease->id}: saved {$primaryName} payment method {$id} does not exist in {$mode} mode.");
                $changes[$column] = null;
                if ($lease->autopay_primary_method === $primaryName) {
                    $changes['autopay_primary_method'] = null;
                }
                $cleared++;
            }
            $apply && $changes && $lease->update($changes);
        }

        $this->info(($apply ? 'Cleared' : 'Dry run, nothing was changed. Would clear')." {$cleared} reference(s).");

        return self::SUCCESS;
    }

    /** True when Stripe knows the object; false only for a definite "does not exist" (any other error is raised). */
    private function exists(callable $lookup): bool
    {
        try {
            $object = $lookup();
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing') {
                return false;
            }
            throw $e;
        }

        // A customer deleted in Stripe still answers, flagged as deleted.
        return ! ($object->deleted ?? false);
    }
}

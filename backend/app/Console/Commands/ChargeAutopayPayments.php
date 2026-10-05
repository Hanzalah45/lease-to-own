<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\AutopayCharger;
use App\Services\BillingClock;
use Illuminate\Console\Command;

/**
 * Charges each due monthly rental payment off-session with the customer's
 * AutoPay method, falling back to their other saved method (client, Joel,
 * 2026-10-05). Runs every 15 minutes: that is what resumes a charge whose
 * outcome was unknown, tries the fallback method after a failed bank debit,
 * and settles an ACH debit that has been processing for days; first attempts
 * for the day wait until the configured morning hour in the client's time
 * zone. See AutopayCharger for the guarantees.
 *
 * Moves real money, so it does nothing unless AUTOPAY_CHARGING_ENABLED is
 * true. `--dry-run` works either way and prints exactly what a real run would do.
 */
class ChargeAutopayPayments extends Command
{
    protected $signature = 'payments:charge-autopay
        {--dry-run : List what would be charged without calling Stripe or changing anything}
        {--lease= : Only consider this lease agreement id}';

    protected $description = 'Charge due monthly rental payments through AutoPay';

    public function handle(AutopayCharger $charger): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! config('billing.autopay_charging_enabled')) {
            $this->info('Automatic charging is switched off (AUTOPAY_CHARGING_ENABLED); nothing was charged.');

            return self::SUCCESS;
        }

        // Loaded up front (a day's due payments is a short list) because paying
        // a row while iterating would shift offset-based chunking past its neighbours.
        $candidates = Payment::query()
            ->where('type', Payment::TYPE_RENTAL)
            ->where('status', Payment::STATUS_PENDING)
            ->whereDate('due_date', '<=', BillingClock::todayDate())
            ->when($this->option('lease'), fn ($q, $lease) => $q->where('lease_agreement_id', $lease))
            ->whereHas('leaseAgreement', fn ($q) => $q->where('autopay_enabled', true)->whereNull('autopay_paused_at'))
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $counts = [];
        $candidates->each(function (Payment $payment) use ($charger, $dryRun, &$counts) {
            if ($dryRun) {
                $plan = $charger->plan($payment);
                $this->line($this->describe($payment, $plan));
                $counts[$plan['action']] = ($counts[$plan['action']] ?? 0) + 1;

                return;
            }

            try {
                $result = $charger->process($payment);
            } catch (\Throwable $e) {
                // One bad payment must not stop the rest of the day's charges.
                report($e);
                $result = 'error';
            }
            $counts[$result] = ($counts[$result] ?? 0) + 1;
        });

        $summary = collect($counts)->map(fn ($n, $what) => "{$what}: {$n}")->implode(', ');
        $this->info(($dryRun ? 'Dry run. ' : '').($summary ?: 'Nothing to charge.'));

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $plan */
    private function describe(Payment $payment, array $plan): string
    {
        $head = sprintf('Payment #%d (lease #%d, due %s, $%s)', $payment->id, $payment->lease_agreement_id, $payment->due_date->toDateString(), number_format((float) $payment->amount, 2));

        return match ($plan['action']) {
            'charge' => sprintf(
                '%s: would charge $%s by %s (%s%s)',
                $head,
                number_format($plan['amount_cents'] / 100, 2),
                $plan['method'] === 'bank' ? 'bank account' : 'card',
                $plan['kind'],
                $plan['fee_cents'] > 0 ? sprintf(', includes $%s card fee', number_format($plan['fee_cents'] / 100, 2)) : '',
            ),
            'resume' => "{$head}: would replay an unconfirmed charge",
            'reconcile' => "{$head}: would check Stripe for the settled bank debit",
            'exhausted' => "{$head}: would mark failed (no method left to try)",
            default => "{$head}: skipped. ".($plan['reason'] ?? ''),
        };
    }
}

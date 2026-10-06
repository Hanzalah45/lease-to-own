<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Services\LeaseEngine;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Puts a lease that was picked up under the old schedule onto the new
 * billing-cycle schedule, from its real pickup date (client, Joel, 2026-10-06).
 * Normally this happens by itself when the customer signs the new contract;
 * this is the manual route (and the way to preview it). Without --apply
 * nothing is saved. See LeaseEngine::rebuildSchedule() for what it refuses.
 */
class RebuildLeaseSchedule extends Command
{
    protected $signature = 'lease:rebuild-schedule
        {--lease=* : Lease agreement id (repeat for several)}
        {--all : Every picked-up lease that has a billing cycle}
        {--pickup= : Use this pickup date (Y-m-d) instead of the date the pickup payment was made}
        {--apply : Save the changes. Without this it only shows what would change}';

    protected $description = 'Rebuild the payment schedule of picked-up leases under the new billing-cycle rules';

    public function handle(): int
    {
        $ids = array_filter((array) $this->option('lease'));
        if (! $ids && ! $this->option('all')) {
            $this->error('Choose --lease=ID (repeatable) or --all.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $pickup = $this->option('pickup') ?: null;

        $leases = LeaseAgreement::query()
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->when(! $ids, fn ($q) => $q->whereNotNull('billing_cycle')
                ->whereHas('application', fn ($a) => $a->where('status', Application::STATUS_FINISHED)))
            ->orderBy('id')
            ->get();

        $counts = ['rebuilt' => 0, 'unchanged' => 0, 'refused' => 0];
        foreach ($leases as $lease) {
            try {
                $r = LeaseEngine::rebuildSchedule($lease, $pickup, $apply);
            } catch (HttpException $e) {
                $this->line("Lease #{$lease->id}: not changed. {$e->getMessage()}");
                $counts['refused']++;

                continue;
            }

            if (! $r['changed']) {
                $this->line("Lease #{$lease->id}: already on the new schedule (pickup {$r['pickup_date']}, {$r['cycle']} cycle).");
                $counts['unchanged']++;

                continue;
            }

            $this->line(sprintf(
                'Lease #%d: %s. Pickup %s, %s cycle. Next payment $%s on %s (was $%s on %s). %d payments remain (was %d).',
                $lease->id,
                $apply ? 'REBUILT' : 'would rebuild',
                $r['pickup_date'],
                $r['cycle'],
                number_format((float) $r['next_amount'], 2),
                $r['next_due'] ?? 'n/a',
                number_format((float) $r['old_next_amount'], 2),
                $r['old_next_due'] ?? 'n/a',
                $r['rows_after'],
                $r['rows_before'],
            ));
            $counts['rebuilt']++;
        }

        $this->info(($apply ? '' : 'Dry run, nothing was changed. ')."Changed: {$counts['rebuilt']}, already current: {$counts['unchanged']}, refused: {$counts['refused']}.");
        if (! $apply && $counts['rebuilt'] > 0) {
            $this->line('Run again with --apply to save these changes.');
        }

        return self::SUCCESS;
    }
}

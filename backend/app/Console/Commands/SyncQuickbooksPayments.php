<?php

namespace App\Console\Commands;

use App\Services\QuickbooksSyncer;
use Illuminate\Console\Command;

/**
 * Sends collected payments to the client's QuickBooks (signed plan, milestone 7). A no-op until the sync is switched on in
 * Settings and every kind of money has an account. See QuickbooksSyncer.
 */
class SyncQuickbooksPayments extends Command
{
    protected $signature = 'quickbooks:sync-payments';

    protected $description = 'Send collected payments to QuickBooks as journal entries';

    public function handle(QuickbooksSyncer $syncer): int
    {
        $result = $syncer->run();

        $this->info("Sent {$result['sent']}, skipped {$result['skipped']}, refused {$result['failed']}, reversed {$result['reversed']}.");
        if ($result['stopped']) {
            $this->warn($result['stopped']);
        }

        return self::SUCCESS;
    }
}

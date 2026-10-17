<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The QuickBooks sync (signed plan, milestone 7): which QuickBooks accounts each kind of money goes to, and where every
     * payment is in the sync. The account choices live in their own one-row table, not on quickbooks_connections, because a
     * reconnect deletes and recreates the connection row and must not wipe the accountant's choices.
     */
    public function up(): void
    {
        Schema::create('quickbooks_sync_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('sync_enabled')->default(false);
            // Only payments recorded as paid on or after this day are sent: a start date, so the books can begin at go-live.
            $table->date('sync_from')->nullable();
            // key => {id, name} for each kind of money (see QuickbooksSyncSetting::ACCOUNT_KEYS).
            $table->json('accounts')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            // null = not sent yet; synced / skipped (counted inside another charge) / failed (needs a person to look).
            $table->string('quickbooks_sync_status', 16)->nullable()->after('quickbooks_record_id');
            $table->timestamp('quickbooks_synced_at')->nullable()->after('quickbooks_sync_status');
            $table->text('quickbooks_sync_error')->nullable()->after('quickbooks_synced_at');
            $table->unsignedTinyInteger('quickbooks_sync_attempts')->default(0)->after('quickbooks_sync_error');
            // The reversing journal entry posted when a synced payment was later refunded or taken back.
            $table->string('quickbooks_reversal_id')->nullable()->after('quickbooks_sync_attempts');
            $table->index('quickbooks_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['quickbooks_sync_status']);
            $table->dropColumn([
                'quickbooks_sync_status',
                'quickbooks_synced_at',
                'quickbooks_sync_error',
                'quickbooks_sync_attempts',
                'quickbooks_reversal_id',
            ]);
        });

        Schema::dropIfExists('quickbooks_sync_settings');
    }
};

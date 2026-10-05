<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client (Joel), 2026-10-05: a permanent fixed two-cycle billing system.
     * The customer chooses the 1st or the 15th of each month; there are no
     * custom dates. Replaces the old free-text `payment_due_day` (which was
     * only ever printed on the contract, never used to compute a due date).
     * That column stays for legacy leases but is no longer written or shown.
     * Nullable: a guest application's lease is created by an admin before the
     * customer has chosen, and the customer picks it right before signing.
     */
    public function up(): void
    {
        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->enum('billing_cycle', ['1st', '15th'])->nullable()->after('payment_due_day');
        });

        // Existing leases keep the day their contract already states.
        DB::table('lease_agreements')->where('payment_due_day', '1')->update(['billing_cycle' => '1st']);
        DB::table('lease_agreements')->where('payment_due_day', '15')->update(['billing_cycle' => '15th']);
    }

    public function down(): void
    {
        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });
    }
};

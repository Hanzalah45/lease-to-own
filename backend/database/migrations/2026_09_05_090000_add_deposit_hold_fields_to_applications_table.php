<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client, 2026-09-05: the security deposit holds the unit for 30 days
     * from the moment the customer signs the lease contract — if they don't
     * pick up and complete their first payment within that window, the
     * deposit is forfeited. deposit_hold_expires_at starts the clock at
     * signing; deposit_forfeited_at records that the forfeiture actually ran
     * (and guards the daily job against re-forfeiting the same application).
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('deposit_hold_expires_at')->nullable()->after('deposit_received');
            $table->timestamp('deposit_forfeited_at')->nullable()->after('deposit_hold_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['deposit_hold_expires_at', 'deposit_forfeited_at']);
        });
    }
};

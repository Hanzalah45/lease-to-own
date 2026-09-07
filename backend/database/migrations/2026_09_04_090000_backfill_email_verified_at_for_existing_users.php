<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registration now leaves new customers unverified (status "pending") until
 * they click the emailed link. Backfilling every account that predates this
 * change so nobody already active gets treated as unverified — this only
 * touches email_verified_at, never status, so nobody's login access changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Not reversible: we don't know which rows were genuinely verified
        // before this backfill vs backfilled by it.
    }
};

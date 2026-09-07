<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tracks when an admin last sent the "Request bank verification" link — lets the UI show whether one is already outstanding. */
    public function up(): void
    {
        Schema::table('risk_profiles', function (Blueprint $table) {
            $table->timestamp('bank_verification_requested_at')->nullable()->after('updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('risk_profiles', function (Blueprint $table) {
            $table->dropColumn('bank_verification_requested_at');
        });
    }
};

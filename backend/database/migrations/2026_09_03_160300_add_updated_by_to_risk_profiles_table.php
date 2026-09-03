<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Which admin last manually overrode this risk profile — previously only a bare updated_at, no actor. */
    public function up(): void
    {
        Schema::table('risk_profiles', function (Blueprint $table) {
            $table->foreignId('updated_by')->nullable()->after('landlord_contact_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('risk_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Which admin last edited/assigned/released this unit — previously only a bare updated_at, no actor. */
    public function up(): void
    {
        Schema::table('equipment_units', function (Blueprint $table) {
            $table->foreignId('updated_by')->nullable()->after('gps_device_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipment_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};

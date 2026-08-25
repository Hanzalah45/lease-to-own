<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reserved for Phase 2 (live GPS provider integration, e.g. Trackimo/Spireon).
     * Table exists now so equipment_units can be linked to location history
     * later without restructuring the platform; nothing writes to it in Phase 1.
     */
    public function up(): void
    {
        Schema::create('gps_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_unit_id')->constrained()->cascadeOnDelete();
            $table->enum('event_type', ['location', 'geofence_status', 'anomaly']);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->enum('anomaly_type', ['tampering', 'offline', 'out_of_area'])->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_units', function (Blueprint $table) {
            $table->id();
            $table->string('model');
            $table->string('serial_number')->unique();
            $table->string('vin')->nullable();
            $table->text('condition_notes')->nullable();
            $table->date('delivery_date')->nullable();
            $table->date('expected_return_or_ownership_date')->nullable();
            $table->enum('status', ['in_stock', 'leased', 'returned', 'owned_by_customer'])->default('in_stock');

            // Phase 2: GPS device link, kept nullable so Phase 1 has no dependency on it.
            $table->string('gps_device_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_units');
    }
};

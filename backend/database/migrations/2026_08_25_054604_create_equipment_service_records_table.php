<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_service_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_unit_id')->constrained()->cascadeOnDelete();
            $table->date('service_date');
            $table->text('description');
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_service_records');
    }
};

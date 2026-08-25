<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Post-approval red flags, tracked continuously rather than only at
     * approval time. gps_anomaly is included now so a Phase 2 GPS event can
     * feed into this same list without a schema change.
     */
    public function up(): void
    {
        Schema::create('risk_red_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_profile_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'missed_payment',
                'late_payment',
                'failed_ach',
                'unreachable_customer',
                'bank_account_change',
                'suspicious_behavior',
                'undisclosed_move',
                'gps_anomaly',
            ]);
            $table->text('description')->nullable();
            $table->timestamp('flagged_at');
            $table->boolean('resolved')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_red_flags');
    }
};

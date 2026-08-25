<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', [
                'submitted',
                'under_review',
                'needs_info',
                'approved',
                'completed',
                'funded_paid',
                'declined',
                'withdrawn',
            ])->default('submitted');

            // Required whenever status is declined or withdrawn.
            $table->text('status_notes')->nullable();

            // Per-application checklist gating progress.
            $table->boolean('signature_received')->default(false);
            $table->boolean('deposit_received')->default(false);

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('internal_notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};

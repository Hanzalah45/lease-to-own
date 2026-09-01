<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admin review pipeline needs a "processed" stage between
     * "completed" and "funded_paid" (payment submitted, awaiting bank
     * confirmation) that the original enum didn't carry. Converting to a
     * plain string (validated in the controller via Rule::in, same as the
     * rest of the app's status fields) avoids a fragile cross-driver ALTER
     * ENUM and matches how RiskProfile's status columns are already handled.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('status', 20)->default('submitted')->change();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->enum('status', [
                'submitted',
                'under_review',
                'needs_info',
                'approved',
                'completed',
                'funded_paid',
                'declined',
                'withdrawn',
            ])->default('submitted')->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The acting user who submitted this application — an admin (New Application wizard) or the customer themselves (self-service). Distinct from reviewed_by, which tracks the last status change. */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};

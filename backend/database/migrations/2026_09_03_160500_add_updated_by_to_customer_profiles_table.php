<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who last wrote to this profile — an admin editing on the customer's
     * behalf, or the customer themselves. Without this, an admin's edit and
     * the customer's own edit are indistinguishable.
     */
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->foreignId('updated_by')->nullable()->after('status_change_emails')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};

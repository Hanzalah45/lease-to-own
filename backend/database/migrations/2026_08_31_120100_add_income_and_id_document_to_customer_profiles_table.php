<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Income (Milestone 3 employment verification / affordability check) and
     * the uploaded ID document path. Stored on a private disk — never a
     * public one — since a driver's license scan is sensitive PII and the
     * host's storage:link is disabled anyway.
     */
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->decimal('monthly_income', 10, 2)->nullable()->after('employer_phone');
            $table->string('government_id_document_path')->nullable()->after('government_id_number');
            $table->string('years_at_residence')->nullable()->after('residence_type');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->dropColumn(['monthly_income', 'government_id_document_path', 'years_at_residence']);
        });
    }
};

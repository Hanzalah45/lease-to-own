<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields the client asked for on 2026-09-04 while reviewing the application
 * flow: a previous address when under 2 years at the current one, two
 * alternate contacts, an employer position, an owner's mortgage detail, a
 * renter's monthly rent, and a utility-bill upload for when the ID address
 * doesn't match the stated residence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->string('previous_address')->nullable()->after('years_at_residence');
            $table->string('alternate_contact_1_name')->nullable()->after('landlord_phone');
            $table->string('alternate_contact_1_phone')->nullable()->after('alternate_contact_1_name');
            $table->string('alternate_contact_2_name')->nullable()->after('alternate_contact_1_phone');
            $table->string('alternate_contact_2_phone')->nullable()->after('alternate_contact_2_name');
            $table->string('employer_position')->nullable()->after('employer_phone');
            $table->decimal('monthly_rent', 10, 2)->nullable()->after('landlord_phone');
            $table->decimal('mortgage_amount', 10, 2)->nullable()->after('monthly_rent');
            $table->string('mortgage_years')->nullable()->after('mortgage_amount');
            $table->string('utility_bill_document_path')->nullable()->after('government_id_document_path');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'previous_address',
                'alternate_contact_1_name',
                'alternate_contact_1_phone',
                'alternate_contact_2_name',
                'alternate_contact_2_phone',
                'employer_position',
                'monthly_rent',
                'mortgage_amount',
                'mortgage_years',
                'utility_bill_document_path',
            ]);
        });
    }
};

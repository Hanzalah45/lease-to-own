<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Term length in months and the remaining wizard-collected billing
     * settings, needed by the Milestone 2 pricing/billing engine — the
     * original schema only carried the raw dollar inputs, not the term.
     */
    public function up(): void
    {
        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->unsignedSmallInteger('term_months')->default(0)->after('equipment_unit_id');
            $table->string('payment_due_day')->nullable()->after('renewal_date');
            $table->boolean('autopay_enabled')->default(false)->after('payment_due_day');
        });
    }

    public function down(): void
    {
        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->dropColumn(['term_months', 'payment_due_day', 'autopay_enabled']);
        });
    }
};

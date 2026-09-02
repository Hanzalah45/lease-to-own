<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->boolean('payment_reminder_emails')->default(true)->after('bank_verified_at');
            $table->boolean('status_change_emails')->default(true)->after('payment_reminder_emails');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->dropColumn(['payment_reminder_emails', 'status_change_emails']);
        });
    }
};

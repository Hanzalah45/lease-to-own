<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The day after a monthly payment's due date the customer is emailed that it has not been received (client, Joel, 2026-11-02).
     * This marks the payment as told, so the daily job can never email the same payment twice.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('overdue_notice_sent_at')->nullable()->after('quickbooks_reversal_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('overdue_notice_sent_at');
        });
    }
};

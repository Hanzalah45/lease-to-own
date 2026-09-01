<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a missed/late-payment flag reference the specific Payment it's
     * about, so the daily overdue check can tell "already flagged" from
     * "flag this" instead of re-flagging the same unpaid installment every run.
     */
    public function up(): void
    {
        Schema::table('risk_red_flags', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('risk_profile_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('risk_red_flags', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
        });
    }
};

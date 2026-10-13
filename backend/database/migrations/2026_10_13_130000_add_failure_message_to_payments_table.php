<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why a charge failed, in the processor's words ("Your card was
     * declined."), so staff can see it next to the payment instead of
     * hunting through Stripe (client, Joel, 2026-10-13). Scheduled rental
     * payments keep theirs per attempt (payment_attempts.failure_message);
     * this covers the deposit and the tracking fee + first month.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('failure_message', 500)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('failure_message');
        });
    }
};

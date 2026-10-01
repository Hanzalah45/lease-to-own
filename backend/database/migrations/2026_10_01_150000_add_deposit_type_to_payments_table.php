<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client (Joel), 2026-10-01: the deposit (+ tracking fee + first month)
     * can now actually be charged via Stripe instead of only ever being
     * attested manually — see StripeDepositPaymentService. A deposit charge
     * is represented as a Payment row (type=deposit) so it flows through the
     * existing webhook/LeaseEngine::applyPaymentStatusChange() machinery for
     * free, rather than needing a parallel one.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('type', ['rental', 'late_fee', 'deposit'])->default('rental')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('type', ['rental', 'late_fee'])->default('rental')->change();
        });
    }
};

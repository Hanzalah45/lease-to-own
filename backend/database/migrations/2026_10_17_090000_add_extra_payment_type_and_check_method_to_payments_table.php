<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extra payments toward the early purchase price (client, Joel, 2026-10-16: "how do customers make extra
     * payments toward the principal?"; the signed plan and contract Section 19 "Additional Funds Received"). One
     * Payment row per extra amount, either charged to the customer's saved bank account or card or recorded by an
     * admin, so each one has its own status, method and receipt like every other payment. A check is also a way
     * to be paid in the office (plan: "a cash payment is recorded manually by an admin").
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('type', ['rental', 'late_fee', 'deposit', 'pickup_balance', 'extra_payment'])->default('rental')->change();
            $table->enum('method', ['ach', 'card', 'cash', 'check', 'other'])->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('type', ['rental', 'late_fee', 'deposit', 'pickup_balance'])->default('rental')->change();
            $table->enum('method', ['ach', 'card', 'cash', 'other'])->nullable()->change();
        });
    }
};

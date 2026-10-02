<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client (Joel), 2026-10-02: "pay deposit only" now defers the $150
     * tracking fee + first month's rent to a separate later charge (made
     * whenever the customer is ready for pickup), instead of always bundling
     * them with the deposit into one charge — see StripeDepositPaymentService.
     * A second, independent Payment row (never sharing a PaymentIntent with
     * the deposit row — see StripeWebhookController::markFromIntent(), which
     * resolves a payment by intent id singularly) represents that balance.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('type', ['rental', 'late_fee', 'deposit', 'pickup_balance'])->default('rental')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('type', ['rental', 'late_fee', 'deposit'])->default('rental')->change();
        });
    }
};

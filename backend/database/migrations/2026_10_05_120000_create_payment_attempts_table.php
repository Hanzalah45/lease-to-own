<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Automatic monthly charging (client, Joel, 2026-10-05). One row per
     * attempt to collect a scheduled rental payment through Stripe: first the
     * customer's primary method, then (if that fails) the other one. Kept as
     * history instead of overwriting payments.stripe_payment_intent_id so a
     * late webhook for an earlier attempt can never be mistaken for the
     * current one, and so a crash between "decided to charge" and "Stripe
     * answered" is recoverable: the `initiated` row (with its idempotency
     * key, payment method and exact amount) is replayed to Stripe, which
     * returns the original result instead of charging twice.
     *
     * A "round" is one primary attempt plus at most one fallback attempt. An
     * admin retry of a failed payment starts the next round.
     */
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_no');
            $table->unsignedSmallInteger('round')->default(1);
            $table->enum('method', ['bank', 'card']);
            // Exactly what was sent to Stripe, fixed at claim time: the total
            // (bank price + card fee) and the fee part of it, in cents, and
            // the saved PaymentMethod it was charged to.
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('fee_cents')->default(0);
            $table->string('stripe_payment_method_id');
            $table->string('idempotency_key')->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->enum('status', ['initiated', 'processing', 'succeeded', 'failed'])->default('initiated');
            $table->string('failure_code')->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'attempt_no']);
        });

        Schema::table('lease_agreements', function (Blueprint $table) {
            // Staff stop for AutoPay on one lease. Once a lease is signed its
            // terms (including autopay_enabled) can no longer be edited, so
            // this is the only way to pause automatic charging.
            $table->timestamp('autopay_paused_at')->nullable()->after('autopay_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->dropColumn('autopay_paused_at');
        });

        Schema::dropIfExists('payment_attempts');
    }
};

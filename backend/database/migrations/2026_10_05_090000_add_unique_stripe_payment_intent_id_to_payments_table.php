<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The webhook resolves a Payment row by stripe_payment_intent_id with a
     * single-row lookup, so two rows must never share one intent (and the
     * lookup shouldn't be a full table scan). A unique index also makes a
     * duplicate PaymentIntent for the same row impossible at the database
     * level. NULLs are not constrained (rows with no charge attempt yet).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unique('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['stripe_payment_intent_id']);
        });
    }
};

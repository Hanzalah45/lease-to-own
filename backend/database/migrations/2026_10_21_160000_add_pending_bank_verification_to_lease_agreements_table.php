<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A bank account that needs two small test deposits (client, Joel, 2026-10-19 checklist, section 2: "ACH bank accounts requiring 2 small
     * test deposits"). When a customer types an account number instead of logging in to their bank, Stripe cannot confirm the account
     * instantly: it sends two small deposits (or one with a code) that arrive in one to two business days, and the customer confirms the
     * amounts on a Stripe page. Until then the account cannot be charged, so it is kept here, apart from the bank account the lease charges.
     *
     * Only the SetupIntent is remembered, with what the customer needs to be told (when the deposits arrive, where to confirm them). When
     * Stripe says the account was verified, it is saved to the lease like any other; when it says verification failed or ran out, this is
     * cleared and the customer is told.
     */
    public function up(): void
    {
        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->string('bank_pending_setup_intent_id', 64)->nullable()->index();
            $table->timestamp('bank_pending_since')->nullable();
            // When Stripe says the deposits arrive by.
            $table->date('bank_pending_arrival')->nullable();
            // Stripe's own page where the customer enters the amounts (or the code). Shown only to the customer.
            $table->string('bank_pending_verify_url', 500)->nullable();
            // amounts (two deposits, enter the amounts) or descriptor_code (one deposit with a code in its description).
            $table->string('bank_pending_kind', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->dropIndex(['bank_pending_setup_intent_id']);
            $table->dropColumn(['bank_pending_setup_intent_id', 'bank_pending_since', 'bank_pending_arrival', 'bank_pending_verify_url', 'bank_pending_kind']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client (Joel), 2026-10-01: AutoPay requires both a bank account and a
     * backup card on file before a lease's deposit can be marked received,
     * collected via a new Stripe Financial Connections step at the
     * waiting_deposit stage — kept fully separate from the existing Plaid
     * verification at in_verification, which stays purely identity/risk.
     *
     * The bank + card ids are stored by type (not "primary"/"backup"
     * position) since which one is primary is the customer's own choice and
     * can change; autopay_primary_method records that choice. The override
     * columns are the audit trail for an admin bypassing the requirement,
     * matching the reviewed_by/recorded_by pattern used elsewhere.
     *
     * plaid_verified_bank_name/_mask persist what RiskScoringService::
     * recordBankVerification() already receives from Plaid but previously
     * only returned transiently in the API response — needed so the new
     * Stripe-linked bank account can be compared against it and flagged on a
     * mismatch.
     */
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->after('bank_verified_at');
            $table->string('plaid_verified_bank_name')->nullable()->after('stripe_customer_id');
            $table->string('plaid_verified_bank_mask')->nullable()->after('plaid_verified_bank_name');
        });

        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->string('stripe_bank_payment_method_id')->nullable()->after('autopay_enabled');
            $table->string('stripe_card_payment_method_id')->nullable()->after('stripe_bank_payment_method_id');
            $table->string('autopay_primary_method')->nullable()->after('stripe_card_payment_method_id');
            $table->foreignId('payment_methods_override_by')->nullable()->after('autopay_primary_method')->constrained('users')->nullOnDelete();
            $table->timestamp('payment_methods_override_at')->nullable()->after('payment_methods_override_by');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->dropColumn(['stripe_customer_id', 'plaid_verified_bank_name', 'plaid_verified_bank_mask']);
        });

        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_methods_override_by');
            $table->dropColumn(['stripe_bank_payment_method_id', 'stripe_card_payment_method_id', 'autopay_primary_method', 'payment_methods_override_at']);
        });
    }
};

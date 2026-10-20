<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Locking a saved bank account or card (client, Joel, 2026-10-19 checklist, sections 2, 4 and 11: "lock method (fraud/dispute)",
     * "hard decline handling", "freeze account on chargeback until resolved").
     *
     * A locked method stays on file but is never charged: not by AutoPay, not by "Pay now", not by staff. Staff lock and unlock it by
     * hand; the system locks it after a hard decline (a closed account, a stolen card) and while a chargeback on it is open. The reason
     * is kept with the lock, and chargeback_dispute_id says which dispute froze the account, so winning it lifts exactly that freeze and
     * leaves a lock a person put there alone.
     */
    public function up(): void
    {
        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->timestamp('bank_locked_at')->nullable();
            $table->string('bank_lock_reason', 200)->nullable();
            $table->foreignId('bank_locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('card_locked_at')->nullable();
            $table->string('card_lock_reason', 200)->nullable();
            $table->foreignId('card_locked_by')->nullable()->constrained('users')->nullOnDelete();
            // Set while a chargeback froze this lease: the Stripe dispute id, and when AutoPay was paused for it (null when it was already paused).
            $table->string('chargeback_dispute_id')->nullable();
            // Which method that chargeback locked (null when it was already locked by someone, so winning does not unlock it).
            $table->string('chargeback_locked_method', 4)->nullable();
            $table->timestamp('chargeback_paused_autopay_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_locked_by');
            $table->dropConstrainedForeignId('card_locked_by');
            $table->dropColumn(['bank_locked_at', 'bank_lock_reason', 'card_locked_at', 'card_lock_reason', 'chargeback_dispute_id', 'chargeback_locked_method', 'chargeback_paused_autopay_at']);
        });
    }
};

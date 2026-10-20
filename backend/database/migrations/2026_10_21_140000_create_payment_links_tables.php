<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment links (client, Joel, 2026-10-19 checklist, section 6: "generate payment link", "send", "resend", "expire", "track link
     * status", "log email used", "timestamp each send", "store link history per customer"). A link lets a customer pay an amount staff
     * chose, from their own phone, without logging in: toward a payment that is due (in whole or in part) or as an extra payment toward
     * the early purchase price.
     *
     * Only a hash of the link's secret is kept, so a leaked database does not leak working links; sending again makes a new secret and the
     * old one stops working. payment_link_sends is every time a link was emailed: to which address, when, by whom, and what became of it.
     */
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_agreement_id')->constrained()->cascadeOnDelete();
            // payment: toward a payment that is due (payment_id). extra: an extra payment toward the early purchase price (payment_id is set once it is made).
            $table->string('kind', 8);
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            // The most the link takes, at the bank price (a card adds its fee on top). For a payment that is due it never takes more than is still due.
            $table->decimal('amount', 10, 2);
            $table->string('description', 200);
            $table->string('token_hash', 64)->unique();
            // The address the link was last sent to.
            $table->string('sent_to', 254)->nullable();
            // created, sent, opened, processing, paid, failed, cancelled. A link past its day that was not paid reads as expired (see PaymentLink::effectiveStatus).
            $table->string('status', 10)->default('created');
            $table->timestamp('expires_at');
            $table->timestamp('first_sent_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedSmallInteger('send_count')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['lease_agreement_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('payment_link_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_link_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            // sent (the mail was handed to the mail service) or failed.
            $table->string('outcome', 8);
            $table->string('error', 300)->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->index('payment_link_id');
        });

        // What a link paid, so the link can say paid (or failed) when the money lands or is returned.
        Schema::table('payment_parts', function (Blueprint $table) {
            $table->foreignId('payment_link_id')->nullable()->constrained('payment_links')->nullOnDelete();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('payment_link_id')->nullable()->constrained('payment_links')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_link_id');
        });
        Schema::table('payment_parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_link_id');
        });
        Schema::dropIfExists('payment_link_sends');
        Schema::dropIfExists('payment_links');
    }
};

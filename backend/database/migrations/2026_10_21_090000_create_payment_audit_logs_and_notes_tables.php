<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The payment audit trail and internal payment notes (client, Joel, 2026-10-19 checklist, sections 9 and 11: "who performed the
     * action, timestamp, action type, payment method, IP/device").
     *
     * payment_audit_logs: one row for every money action, made by staff, by a customer, or by the system (a webhook, the daily
     * AutoPay run). Rows are only ever added, never changed (no updated_at); the portal has no screen or route that edits them.
     *
     * payment_notes: what staff write about a payment, a failed one, a refund or a chargeback. Customers never see them.
     */
    public function up(): void
    {
        Schema::create('payment_audit_logs', function (Blueprint $table) {
            $table->id();
            // Who: a person, or null for the system itself (a Stripe event, the scheduler).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // staff, customer or system: kept as words so the row still reads right if the account is later deleted.
            $table->string('actor_type', 8);
            $table->string('actor_name', 120)->nullable();
            $table->string('action', 48);
            $table->foreignId('lease_agreement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            // How the money moved (ach, card, cash, check, other, split), when the action involves any.
            $table->string('method', 16)->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('summary', 400);
            $table->json('details')->nullable();
            // IP address (IPv6 is at most 45 characters) and the browser or device, for actions made from a screen.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['payment_id', 'created_at']);
            $table->index(['lease_agreement_id', 'created_at']);
            $table->index('action');
        });

        Schema::create('payment_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            // general, failed, refund or chargeback.
            $table->string('kind', 12);
            $table->text('body');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['payment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notes');
        Schema::dropIfExists('payment_audit_logs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refunds and chargebacks (client, Joel, 2026-10-19: "we have to make sure that I'm able to give a refund", and a refund or a dispute
     * handled in Stripe has to show up in the portal and in QuickBooks).
     *
     * payment_refunds: every refund of a paid payment is a row of its own (money going back), whether staff made it in the portal, it
     * was recorded after money was given back by hand, it was made in the Stripe dashboard, or a chargeback was lost. The payment keeps
     * what it was; its refunded amount is the sum of these rows. The quickbooks_* columns follow the payment's own sync columns.
     *
     * payment_disputes: a chargeback Stripe reported against a payment, from the day it opened to how it ended.
     */
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            // The money returned to the customer, card fee included (the same basis as Payment::totalCharged()).
            $table->decimal('amount', 10, 2);
            $table->string('reason', 32);
            $table->text('note')->nullable();
            // portal (made in Stripe from here), manual (given back outside the portal and recorded), stripe (made in the dashboard), dispute (a lost chargeback).
            $table->string('source', 16);
            // pending (Stripe has not finished it, or gave no answer), succeeded, failed.
            $table->string('status', 16);
            $table->string('stripe_refund_id')->nullable()->unique();
            $table->text('failure_message')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->string('quickbooks_record_id')->nullable();
            $table->string('quickbooks_sync_status', 16)->nullable();
            $table->timestamp('quickbooks_synced_at')->nullable();
            $table->text('quickbooks_sync_error')->nullable();
            $table->unsignedTinyInteger('quickbooks_sync_attempts')->default(0);
            $table->timestamps();
            $table->index('status');
            $table->index('quickbooks_sync_status');
        });

        Schema::create('payment_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_dispute_id')->unique();
            $table->decimal('amount', 10, 2);
            $table->string('reason', 64)->nullable();
            // Stripe's own status: needs_response, under_review, won, lost, warning_closed, and so on.
            $table->string('status', 32);
            $table->timestamp('evidence_due_by')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_disputes');
        Schema::dropIfExists('payment_refunds');
    }
};

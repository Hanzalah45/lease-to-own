<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Split payments (client, Joel, 2026-10-19 checklist, section 7: "split by method (cash + card, card + card, ACH + card)", "split by
     * amount", "split by sequence (partial now, finish later)", "auto-calculate remaining balance", "show split breakdown in history"; and
     * section 1: "record partial payment", "record typed-amount payment"). A real case: a customer who wanted to pay part of the
     * deposit in cash and the rest on a card.
     *
     * A payment keeps its full amount and is paid in pieces, each its own row here: how much, by which method, what the card fee was,
     * when, and (for a card or bank piece) the Stripe charge behind it. A payment that is paid in one go has no pieces at all, so
     * nothing about the ordinary flows changes. The payment becomes paid when its paid pieces add up to its amount, and then carries
     * the total card fee and the method ('split' when the pieces used different ones).
     */
    public function up(): void
    {
        Schema::create('payment_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('method', 5);
            // The bank price of this piece (the card fee is on top, as for any payment).
            $table->decimal('amount', 10, 2);
            $table->decimal('card_fee_amount', 10, 2)->default(0);
            // pending (a bank debit on its way, or a card charge nobody has an answer for), paid, failed.
            $table->string('status', 8);
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('failure_message', 500)->nullable();
            $table->date('paid_date')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            // staff, customer or system: who made this piece.
            $table->string('started_by', 8);
            $table->timestamps();
            $table->index(['payment_id', 'status']);
        });

        // A refund is made against one piece (its own card or bank account), so it says which.
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->foreignId('payment_part_id')->nullable()->constrained('payment_parts')->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('method', ['ach', 'card', 'cash', 'check', 'other', 'split'])->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('method', ['ach', 'card', 'cash', 'check', 'other'])->nullable()->change();
        });
        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_part_id');
        });
        Schema::dropIfExists('payment_parts');
    }
};

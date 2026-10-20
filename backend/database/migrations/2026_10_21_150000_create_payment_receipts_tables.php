<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formal receipts (client, Joel, 2026-10-19 checklist, section 8: "auto-send receipt after payment", "auto-send receipt after refund",
     * "manual resend receipt", "receipt log (timestamp, email, delivery status)", "store receipt copies for audit"). A receipt is a numbered
     * record of money that came in (a whole payment, or one piece of a payment paid in parts) or went back (a refund). It is written once,
     * when the money lands, and never changed: `details` is what it said, the PDF is the stored copy of it, and `pdf_sha256` proves the copy
     * was not altered. Sending it again attaches that same copy.
     *
     * `source_key` is what the receipt is for ("payment:12", "part:5", "refund:9"), unique, so the same money can never get two receipts
     * however many times the code that issues them runs. The number is set right after the row exists (it is made from the row's own id,
     * so numbers only ever go up).
     *
     * payment_receipt_sends is every time a receipt was emailed: to which address, when, automatically or by whom, and what the mail
     * service answered. "Delivered" and "opened" are not known to the portal: that needs the email service's own events.
     */
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 20)->nullable()->unique();
            $table->string('source_key', 40)->unique();
            // payment: money that came in (a whole payment or a piece of one). refund: money that went back.
            $table->string('kind', 8);
            $table->foreignId('lease_agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_part_id')->nullable()->constrained('payment_parts')->nullOnDelete();
            $table->foreignId('payment_refund_id')->nullable()->constrained('payment_refunds')->nullOnDelete();
            // What came in (card fee included) or went back, the one figure on top of the receipt.
            $table->decimal('amount', 10, 2);
            // Everything the receipt says, as it was said: names, lines, method, balance. Never rewritten.
            $table->json('details');
            // The stored copy, on the private disk, and its fingerprint.
            $table->string('pdf_path', 255)->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->index(['lease_agreement_id', 'issued_at']);
        });

        Schema::create('payment_receipt_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_receipt_id')->constrained('payment_receipts')->cascadeOnDelete();
            $table->string('email', 254);
            // auto: sent when the money landed. manual: staff sent it again.
            $table->string('trigger', 6);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            // sent (the mail was handed to the mail service) or failed.
            $table->string('outcome', 8);
            $table->string('error', 300)->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->index('payment_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipt_sends');
        Schema::dropIfExists('payment_receipts');
    }
};

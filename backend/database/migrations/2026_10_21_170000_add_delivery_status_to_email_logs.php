<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivered or bounced (client, Joel, 2026-10-15 and the 2026-10-19 checklist, sections 6 and 8: "track link status (delivered)",
     * "receipt log (delivery status)"). Until now an email was only known to have been accepted by the email service. Amazon SES reports
     * what happened next (delivered to the mailbox, bounced, marked as spam, held up) through an Amazon SNS message, which the portal
     * receives and writes here, against the email it belongs to. SES names each message with its own id, kept apart from the id the
     * portal's own message carries.
     *
     * Receipts and payment links send their own emails, so their send logs point at the email log row of that email and show the same
     * result.
     */
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            // The id Amazon SES gave the message when it accepted it: how its later events are matched.
            $table->string('ses_message_id', 191)->nullable()->index();
            // delivered, bounced, complaint (the person marked it as spam), delayed (SES is still trying), rejected. Null: no result yet, or not tracked.
            $table->string('delivery_status', 12)->nullable();
            $table->timestamp('delivered_at')->nullable();
            // Why, in the mailbox provider's words: a bounce's type and reason, a delay's cause.
            $table->string('delivery_detail', 500)->nullable();
            $table->timestamp('delivery_updated_at')->nullable();
        });

        Schema::table('payment_receipt_sends', function (Blueprint $table) {
            $table->foreignId('email_log_id')->nullable()->constrained('email_logs')->nullOnDelete();
        });
        Schema::table('payment_link_sends', function (Blueprint $table) {
            $table->foreignId('email_log_id')->nullable()->constrained('email_logs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_link_sends', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_log_id');
        });
        Schema::table('payment_receipt_sends', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_log_id');
        });
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex(['ses_message_id']);
            $table->dropColumn(['ses_message_id', 'delivery_status', 'delivered_at', 'delivery_detail', 'delivery_updated_at']);
        });
    }
};

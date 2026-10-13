<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A record of every email the system sends (client, Joel, 2026-10-13): who
     * it went to, what it was, and whether the email service accepted it. Built
     * after a customer's signing links went to a mistyped address and nothing
     * showed anywhere. The message text is deliberately NOT stored (it carries
     * private sign-in links). Delivery results (delivered, bounced) come later
     * and use provider_message_id to find the row.
     *
     * to_address is not indexed: a full index on a 254-character column is too
     * long for some MySQL setups (see Schema::defaultStringLength in
     * AppServiceProvider) and the table is searched by hand, not by key.
     */
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('to_address', 254);
            $table->string('subject', 255)->nullable();
            // Class name of the notification or mailable that produced it.
            $table->string('kind', 120)->nullable();
            // sending: handed to the email service, no answer yet. sent: accepted. failed: refused or errored.
            $table->string('status', 12)->default('sending');
            $table->string('error', 1000)->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};

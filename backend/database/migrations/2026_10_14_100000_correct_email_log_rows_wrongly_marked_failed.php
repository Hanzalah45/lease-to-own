<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first release of the email log (2026-10-13) matched a "sent" event to its
 * "sending" row by object, but the real email service sends a copy of every
 * message, so no real email was ever matched and each one ended up marked
 * "failed: the email service did not confirm this message" (Joel saw it on the
 * Contract voided and Contract signed emails). They were not failures: no email
 * error was logged anywhere on the server for that period, and an email the
 * service refuses always carries the service's own error text instead. Only
 * rows with that exact generic text are corrected; a real failure keeps its
 * message.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('email_logs')
            ->where('status', 'failed')
            ->where('error', 'like', 'The email service did not confirm this message%')
            ->update(['status' => 'sent', 'error' => null]);
    }

    public function down(): void
    {
        // Not reversed: the old value was the bug.
    }
};

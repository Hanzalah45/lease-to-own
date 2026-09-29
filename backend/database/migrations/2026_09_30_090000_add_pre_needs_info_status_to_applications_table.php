<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real gap found live 2026-09-30 (Joel, application #9): the "Request
     * Info" button has always been offered on both waiting_review and
     * waiting_approval, but LEGAL_STATUS_TRANSITIONS only ever allowed
     * needs_info from waiting_review — asking for something during the
     * approval-call stage was rejected outright. Fixing that reachability
     * alone isn't enough: InfoRequestResponder::respond() unconditionally
     * sends an answered request back to waiting_review, so a request opened
     * from waiting_approval would silently bounce the application back to
     * the very first stage once answered, undoing real progress. This
     * column remembers which stage to return to.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('pre_needs_info_status')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('pre_needs_info_status');
        });
    }
};

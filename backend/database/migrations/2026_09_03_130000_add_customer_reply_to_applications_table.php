<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kept separate from status_notes (the admin's ask) so a "needs info"
     * exchange preserves both sides — what was asked, and what the customer
     * said back — instead of the reply overwriting the question.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('customer_reply')->nullable()->after('status_notes');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('customer_reply');
        });
    }
};

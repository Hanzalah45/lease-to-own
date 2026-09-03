<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Superseded by application_info_requests — these two columns only ever held the latest round, losing history on every new request. */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['customer_reply', 'customer_reply_has_document']);
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('customer_reply')->nullable()->after('status_notes');
            $table->boolean('customer_reply_has_document')->default(false)->after('customer_reply');
        });
    }
};

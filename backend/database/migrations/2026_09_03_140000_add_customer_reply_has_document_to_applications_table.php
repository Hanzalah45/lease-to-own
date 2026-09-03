<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flags whether the customer's most recent "needs info" reply included a
     * new document — without this, an admin has no way to tell a document
     * was just resubmitted versus already being on file from before.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('customer_reply_has_document')->default(false)->after('customer_reply');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('customer_reply_has_document');
        });
    }
};

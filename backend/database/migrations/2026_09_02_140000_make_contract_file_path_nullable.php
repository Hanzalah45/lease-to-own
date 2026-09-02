<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1's built-in signing module has no server-generated PDF — the
     * live LeaseAgreement is the document (viewed/printed from the portal).
     * file_path stays null until a stored copy (or a DocuSign/HelloSign
     * envelope) exists to point to.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('file_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('file_path')->nullable(false)->change();
        });
    }
};

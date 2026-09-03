<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Who resolved this flag and when — previously just a boolean, indistinguishable from any other edit to the row. */
    public function up(): void
    {
        Schema::table('risk_red_flags', function (Blueprint $table) {
            $table->foreignId('resolved_by')->nullable()->after('resolved')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('risk_red_flags', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn('resolved_at');
        });
    }
};

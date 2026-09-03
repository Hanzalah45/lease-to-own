<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A permanent snapshot of the exact document rendered at signing time.
     * Without this, a PDF that ever needs regenerating (its file went
     * missing) would rebuild from whatever the Blade template says *today* —
     * which could differ from what the customer actually saw and agreed to.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->longText('document_html')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('document_html');
        });
    }
};

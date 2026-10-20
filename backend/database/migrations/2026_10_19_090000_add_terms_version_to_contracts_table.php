<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which wording of the agreement a signature was given on (client, Joel, 2026-10-19: the early purchase price in Section 3 now
     * deducts any additional funds, as the Technical Project Plan says). Every contract signed before this change is version 1 and
     * keeps showing the wording it was signed with; new signatures record the current version. Only a default is added, no row changes.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedTinyInteger('terms_version')->default(1)->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('terms_version');
        });
    }
};

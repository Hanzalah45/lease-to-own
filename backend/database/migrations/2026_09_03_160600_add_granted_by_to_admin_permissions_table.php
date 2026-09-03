<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which super admin granted this restriction row. Note: the permission
     * set is still recreated wholesale on every change (delete + reinsert),
     * so this records who set the CURRENT set, not a history of prior sets.
     */
    public function up(): void
    {
        Schema::table('admin_permissions', function (Blueprint $table) {
            $table->foreignId('granted_by')->nullable()->after('permission')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admin_permissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('granted_by');
        });
    }
};

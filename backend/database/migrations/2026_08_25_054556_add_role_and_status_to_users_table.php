<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The three account types the platform recognizes: super_admin (the
     * client — one account, created only by seeding), admin (staff, created
     * only by a super admin), customer (self-registers). Admins get full
     * access by default; the admin_permissions table holds an *opt-in
     * restriction* list, not a grant list — see User::hasAdminPermission().
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['customer', 'admin', 'super_admin'])->default('customer')->after('email');
            $table->enum('status', ['active', 'suspended', 'pending'])->default('active')->after('role');
            $table->string('phone')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'status', 'phone']);
        });
    }
};

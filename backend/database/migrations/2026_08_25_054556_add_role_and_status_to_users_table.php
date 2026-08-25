<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two account types the platform recognizes.
     * Admin sub-permissions (application review, risk assessment, contract
     * generation, equipment tracking, payment tracking) are handled by the
     * separate admin_permissions table, not by this column.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['customer', 'admin'])->default('customer')->after('email');
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('zip');
            $table->text('internal_notes')->nullable()->after('move_notification_agreed');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'internal_notes']);
        });
    }
};

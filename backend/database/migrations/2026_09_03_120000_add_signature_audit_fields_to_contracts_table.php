<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records what the typed e-signature actually said, plus the request it
     * came from — the minimum a click-through signature needs to be
     * defensible later (who typed what, from where, with what client).
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('signer_name')->nullable()->after('signer_user_id');
            $table->string('ip_address', 45)->nullable()->after('signed_at');
            $table->string('user_agent', 512)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['signer_name', 'ip_address', 'user_agent']);
        });
    }
};

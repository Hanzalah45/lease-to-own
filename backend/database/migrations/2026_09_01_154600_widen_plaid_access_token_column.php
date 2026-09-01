<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CustomerProfile casts plaid_access_token as 'encrypted', which stores
     * a base64-wrapped JSON payload (iv/value/mac/tag) — routinely 300-450+
     * chars for even a short plaintext token. The original VARCHAR(255)
     * column truncates that on every save, so bank verification failed for
     * every customer with a MySQL "Data too long" error.
     */
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->text('plaid_access_token')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $table) {
            $table->string('plaid_access_token')->nullable()->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row, always — this app has exactly one QuickBooks company (Joel's
     * business), not one per customer. A dedicated table rather than a
     * config/env value because access_token/refresh_token rotate on every
     * refresh and the app has to be able to write that back at runtime,
     * which a .env file can't do safely; client_id/client_secret (the static
     * app-level credential, registered once under the developer's own Intuit
     * account) stay in config/services.php.
     */
    public function up(): void
    {
        Schema::create('quickbooks_connections', function (Blueprint $table) {
            $table->id();
            // Which QuickBooks company this is — issued by Intuit at
            // authorization time, required on every API call.
            $table->string('realm_id');
            $table->text('access_token');
            $table->timestamp('access_token_expires_at');
            $table->text('refresh_token');
            $table->timestamp('refresh_token_expires_at');
            $table->foreignId('connected_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_connections');
    }
};

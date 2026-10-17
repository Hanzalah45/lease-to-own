<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ending a lease that was already picked up (client, Joel, 2026-10-16: "is it possible for me to cancel those types of
     * situations or will I always need you?"). Until now nothing stopped the monthly payments and AutoPay of a customer who
     * returned the equipment, and an early purchase could not be completed. These columns record that and why, so every page
     * can say the lease is over and nothing more is asked of the customer.
     */
    public function up(): void
    {
        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 20)->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ended_by');
            $table->dropColumn(['ended_at', 'ended_reason']);
        });
    }
};

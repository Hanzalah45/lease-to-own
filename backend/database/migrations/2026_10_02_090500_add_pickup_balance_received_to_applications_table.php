<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client (Joel), 2026-10-02: "pay deposit only" defers the tracking fee +
     * first month's rent to a later, separate charge — this flag is the
     * pickup_balance sibling of deposit_received, toggled the same way (auto
     * on a successful Stripe charge, or manually by an admin as a fallback).
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('pickup_balance_received')->default(false)->after('deposit_received');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('pickup_balance_received');
        });
    }
};

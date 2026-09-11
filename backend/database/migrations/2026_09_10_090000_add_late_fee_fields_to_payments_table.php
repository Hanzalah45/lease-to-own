<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('type', ['rental', 'late_fee'])->default('rental')->after('lease_agreement_id');

            // Set only on a late_fee row — the overdue rental payment it was
            // charged against. Also doubles as the idempotency guard for
            // payments:charge-late-fees (contract Section 8: once per missed
            // payment, never recurring on the same one).
            $table->foreignId('late_fee_for_payment_id')->nullable()->after('type')
                ->constrained('payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('late_fee_for_payment_id');
            $table->dropColumn('type');
        });
    }
};

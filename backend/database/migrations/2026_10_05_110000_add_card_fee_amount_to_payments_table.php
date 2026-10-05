<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dual pricing (client, Joel, 2026-10-05): a card payment costs more than
     * a bank payment. `amount` stays the BANK price so every consumer that
     * sums or reads it (EPO arrears, late fees, ownership, dashboards) is
     * untouched; this column holds the extra card fee actually charged on top
     * (0 for a bank payment). Total charged = amount + card_fee_amount.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('card_fee_amount', 10, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('card_fee_amount');
        });
    }
};

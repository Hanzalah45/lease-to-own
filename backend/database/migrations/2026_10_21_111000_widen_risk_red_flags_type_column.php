<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A chargeback and a hard decline (a closed account, a stolen card) now raise a red flag on the customer (client, Joel, 2026-10-19
     * checklist, section 11: "fraud flagging"). The original column was a fixed list of eight kinds; a plain string, as the other status
     * columns of the app already are (see widen_applications_status_column), avoids a fragile ALTER ENUM on every new kind.
     */
    public function up(): void
    {
        Schema::table('risk_red_flags', function (Blueprint $table) {
            $table->string('type', 32)->change();
        });
    }

    public function down(): void
    {
        // Left as a string: narrowing it back would fail on any row of a newer kind.
    }
};

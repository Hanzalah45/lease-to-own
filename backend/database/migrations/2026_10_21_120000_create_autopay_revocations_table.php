<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A customer turning AutoPay off from the portal (client, Joel, 2026-10-19 checklist, section 5: "disable AutoPay", "AutoPay
     * revocation window (3 business days)"). The AutoPay authorization in the contract says the customer may revoke by written notice at
     * least three business days before the next scheduled payment, so a request is kept with the day it was made and the day it takes
     * effect (three business days later), and AutoPay keeps charging until then. When that day comes the system turns AutoPay off by
     * itself. Rows are never changed except to say what became of the request.
     */
    public function up(): void
    {
        Schema::create('autopay_revocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('requested_at');
            // The calendar day (Central) AutoPay stops: three business days after the request.
            $table->date('effective_on');
            // portal (the customer, logged in) or staff (taken from the customer in writing and entered by staff).
            $table->string('via', 8);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            // pending, applied (AutoPay was switched off on the day) or withdrawn (the customer kept AutoPay).
            $table->string('status', 10)->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['lease_agreement_id', 'status']);
            $table->index(['status', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autopay_revocations');
    }
};

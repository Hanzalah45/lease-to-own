<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The AutoPay tick box (client, Joel, 2026-10-16 and 2026-10-20): a customer who signed without AutoPay turns it on from the
     * portal instead of signing the contract again. Every agreement is kept as its own record: who, when, from where, the exact words
     * agreed to and the terms shown next to them, like an addendum to the lease agreement.
     *
     * autopay_started_on is the first day AutoPay may charge a payment: a payment that was already due earlier stays payable by the
     * customer but is never charged by surprise the moment the box is ticked. Null (every existing lease) means no limit.
     */
    public function up(): void
    {
        Schema::create('autopay_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('wording_version');
            $table->text('wording');
            $table->json('authorization');
            $table->string('via', 16);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('agreed_at');
            $table->timestamps();
        });

        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->date('autopay_started_on')->nullable()->after('autopay_paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('lease_agreements', function (Blueprint $table) {
            $table->dropColumn('autopay_started_on');
        });

        Schema::dropIfExists('autopay_agreements');
    }
};

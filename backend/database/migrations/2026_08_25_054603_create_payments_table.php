<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_agreement_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('due_date');
            $table->date('paid_date')->nullable();
            $table->enum('method', ['ach', 'card', 'cash', 'other'])->nullable();
            $table->enum('status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');

            // Set when an admin records a manual/cash payment rather than Stripe.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('quickbooks_record_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

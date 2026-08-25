<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('equipment_unit_id')->nullable()->constrained()->nullOnDelete();

            $table->date('start_date');
            $table->date('renewal_date');

            // Pricing engine inputs
            $table->decimal('monthly_rental_payment', 10, 2);
            $table->decimal('sales_tax_rate', 6, 4)->default(0);
            $table->decimal('security_deposit', 10, 2)->default(0);
            $table->decimal('cash_price', 10, 2);
            $table->decimal('total_rental_purchase_price', 10, 2);
            $table->decimal('rental_payments_paid_to_date', 10, 2)->default(0);
            $table->decimal('additional_funds', 10, 2)->default(0);

            $table->enum('ownership_status', ['leasing', 'owned'])->default('leasing');

            // Optional add-ons
            $table->boolean('ldw_selected')->default(false);
            $table->decimal('ldw_amount', 10, 2)->nullable();
            $table->string('promo_code')->nullable();
            $table->decimal('promo_discount', 10, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_agreements');
    }
};

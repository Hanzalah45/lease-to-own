<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Identity verification
            $table->string('government_id_type')->nullable();
            $table->string('government_id_number')->nullable();
            $table->timestamp('identity_verified_at')->nullable();

            // Address / residence rule
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->enum('residence_type', ['apartment', 'house', 'other'])->nullable();
            $table->string('landlord_name')->nullable();
            $table->string('landlord_phone')->nullable();
            $table->boolean('move_notification_agreed')->default(false);

            // Employment
            $table->string('employment_status')->nullable();
            $table->string('employer_name')->nullable();
            $table->string('employer_phone')->nullable();

            // Linked bank account (Plaid)
            $table->string('plaid_item_id')->nullable();
            $table->string('plaid_access_token')->nullable();
            $table->timestamp('bank_verified_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};

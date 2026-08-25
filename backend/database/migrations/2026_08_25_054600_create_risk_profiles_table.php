<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->enum('identity_verification_status', ['pending', 'verified', 'failed'])->default('pending');
            $table->enum('employment_verification_status', ['pending', 'verified', 'failed'])->default('pending');
            $table->enum('bank_verification_status', ['pending', 'verified', 'failed'])->default('pending');
            $table->enum('background_check_status', ['pending', 'clear', 'flagged'])->default('pending');
            $table->text('background_check_notes')->nullable();

            $table->unsignedTinyInteger('risk_score')->nullable();

            // Landlord contact is only triggered for specific borderline situations,
            // tracked here rather than fired automatically for every applicant.
            $table->boolean('landlord_contact_required')->default(false);
            $table->string('landlord_contact_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_profiles');
    }
};

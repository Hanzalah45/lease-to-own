<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signer_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('file_path');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('signed_at')->nullable();

            // Left null for Phase 1's built-in signing module; set once a
            // DocuSign/HelloSign integration is wired up.
            $table->string('external_provider')->nullable();
            $table->string('external_envelope_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};

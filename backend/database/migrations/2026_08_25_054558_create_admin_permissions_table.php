<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Granular permission set for admin users, per the doc's Admin User entity:
     * application review, risk assessment, contract generation, equipment
     * tracking, payment tracking. One admin can hold several permissions.
     */
    public function up(): void
    {
        Schema::create('admin_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('permission', [
                'application_review',
                'risk_assessment',
                'contract_generation',
                'equipment_tracking',
                'payment_tracking',
            ]);
            $table->timestamps();

            $table->unique(['user_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_permissions');
    }
};

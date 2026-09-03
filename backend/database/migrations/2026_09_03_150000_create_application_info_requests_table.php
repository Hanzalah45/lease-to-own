<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per "needs info" round-trip — mirrors dealer_notes' append-only
     * shape rather than the single overwritable status_notes/customer_reply
     * columns tried first. Every ask and every reply stays on the record,
     * including which document (if any) was attached to that specific reply,
     * so a second round never erases the first.
     */
    public function up(): void
    {
        Schema::create('application_info_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('request_text');
            $table->timestamp('replied_at')->nullable();
            $table->text('reply_text')->nullable();
            $table->string('reply_document_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_info_requests');
    }
};

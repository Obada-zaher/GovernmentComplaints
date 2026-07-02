<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('citizen_id')->constrained('users')->cascadeOnDelete();
            $table->string('client_uuid')->unique();
            $table->json('payload');
            $table->enum('status', ['pending', 'synced', 'failed'])->default('pending');
            $table->foreignId('synced_complaint_id')->nullable()->constrained('complaints')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamp('submitted_offline_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_submissions');
    }
};

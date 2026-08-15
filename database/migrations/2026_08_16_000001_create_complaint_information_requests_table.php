<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaint_information_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'responded', 'completed', 'cancelled'])->default('pending');
            $table->text('message');
            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['complaint_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_information_requests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('complaint_number')->unique();
            $table->foreignId('citizen_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('complaint_categories')->nullOnDelete();
            $table->foreignId('priority_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description');
            $table->enum('status', [
                'submitted',
                'under_review',
                'assigned',
                'in_progress',
                'waiting_citizen',
                'resolved',
                'closed',
                'rejected',
                'escalated',
            ])->default('submitted');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('address')->nullable();
            $table->enum('source', ['web', 'mobile', 'offline_sync', 'admin'])->default('web');
            $table->string('client_uuid')->nullable()->index();
            $table->decimal('classification_confidence', 5, 4)->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->boolean('is_sla_breached')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('complaint_number');
            $table->index('citizen_id');
            $table->index('department_id');
            $table->index('category_id');
            $table->index('priority_id');
            $table->index('assigned_employee_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};

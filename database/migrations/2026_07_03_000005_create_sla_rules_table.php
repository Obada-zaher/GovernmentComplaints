<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('complaint_categories')->nullOnDelete();
            $table->foreignId('priority_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('response_time_hours')->default(24);
            $table->unsignedInteger('resolution_time_hours')->default(72);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_rules');
    }
};

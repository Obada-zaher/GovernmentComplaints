<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaint_classification_rules', function (Blueprint $table): void {
            $table->string('language', 20)->default('mixed')->after('is_active');
            $table->string('normalized_keyword')->nullable()->after('language');
            $table->text('notes')->nullable()->after('normalized_keyword');

            $table->index('department_id');
            $table->index('category_id');
            $table->index('keyword');
            $table->index('is_active');
            $table->index('normalized_keyword');
        });

        Schema::create('classification_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('complaint_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('predicted_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('predicted_category_id')->nullable()->constrained('complaint_categories')->nullOnDelete();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->json('scores')->nullable();
            $table->json('used_rules')->nullable();
            $table->boolean('accepted')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_logs');

        Schema::table('complaint_classification_rules', function (Blueprint $table): void {
            $table->dropIndex(['department_id']);
            $table->dropIndex(['category_id']);
            $table->dropIndex(['keyword']);
            $table->dropIndex(['is_active']);
            $table->dropIndex(['normalized_keyword']);
            $table->dropColumn(['language', 'normalized_keyword', 'notes']);
        });
    }
};

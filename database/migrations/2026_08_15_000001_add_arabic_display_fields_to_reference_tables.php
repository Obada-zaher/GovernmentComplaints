<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->string('name_ar')->nullable()->after('name');
            $table->text('description_ar')->nullable()->after('description');
        });

        Schema::table('complaint_categories', function (Blueprint $table): void {
            $table->string('name_ar')->nullable()->after('name');
            $table->text('description_ar')->nullable()->after('description');
        });

        Schema::table('priorities', function (Blueprint $table): void {
            $table->string('name_ar')->nullable()->after('name');
            $table->text('description_ar')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn(['name_ar', 'description_ar']);
        });

        Schema::table('complaint_categories', function (Blueprint $table): void {
            $table->dropColumn(['name_ar', 'description_ar']);
        });

        Schema::table('priorities', function (Blueprint $table): void {
            $table->dropColumn(['name_ar', 'description_ar']);
        });
    }
};

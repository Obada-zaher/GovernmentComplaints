<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->unique()->after('email');
            $table->string('national_id')->nullable()->unique()->after('phone');
            $table->enum('role', ['citizen', 'employee', 'admin'])->default('citizen')->after('password');
            $table->foreignId('department_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('department_id');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropUnique(['phone']);
            $table->dropUnique(['national_id']);
            $table->dropColumn([
                'phone',
                'national_id',
                'role',
                'department_id',
                'is_active',
                'phone_verified_at',
                'last_login_at',
            ]);
        });
    }
};

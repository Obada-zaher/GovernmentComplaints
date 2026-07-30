<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->reconcileUsersTable();
        $this->reconcileOtpCodesTable();
    }

    private function reconcileUsersTable(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $columns = [
            'phone' => fn (Blueprint $table) => $table->string('phone')->nullable(),
            'national_id' => fn (Blueprint $table) => $table->string('national_id')->nullable(),
            'role' => fn (Blueprint $table) => $table->enum('role', ['citizen', 'employee', 'admin'])->default('citizen'),
            'department_id' => fn (Blueprint $table) => $table->unsignedBigInteger('department_id')->nullable(),
            'is_active' => fn (Blueprint $table) => $table->boolean('is_active')->default(true),
            'phone_verified_at' => fn (Blueprint $table) => $table->timestamp('phone_verified_at')->nullable(),
            'last_login_at' => fn (Blueprint $table) => $table->timestamp('last_login_at')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('users', $column)) {
                Schema::table('users', $definition);
            }
        }
    }

    private function reconcileOtpCodesTable(): void
    {
        if (! Schema::hasTable('otp_codes')) {
            Schema::create('otp_codes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('code_hash');
                $table->enum('purpose', ['register', 'verify_email', 'login'])->default('register');
                $table->timestamp('expires_at');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
            });

            return;
        }

        $columns = [
            'user_id' => fn (Blueprint $table) => $table->unsignedBigInteger('user_id')->nullable(),
            'phone' => fn (Blueprint $table) => $table->string('phone')->nullable(),
            'email' => fn (Blueprint $table) => $table->string('email')->nullable(),
            'code_hash' => fn (Blueprint $table) => $table->string('code_hash')->nullable(),
            'purpose' => fn (Blueprint $table) => $table->string('purpose')->default('register'),
            'expires_at' => fn (Blueprint $table) => $table->timestamp('expires_at')->nullable(),
            'attempts' => fn (Blueprint $table) => $table->unsignedTinyInteger('attempts')->default(0),
            'used_at' => fn (Blueprint $table) => $table->timestamp('used_at')->nullable(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('otp_codes', $column)) {
                Schema::table('otp_codes', $definition);
            }
        }
    }

    public function down(): void
    {
        // Intentionally left blank: this migration repairs production schema and
        // must not remove columns or tables that may have existed beforehand.
    }
};

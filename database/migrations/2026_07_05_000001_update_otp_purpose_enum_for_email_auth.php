<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('otp_codes') && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE otp_codes MODIFY purpose ENUM('register', 'verify_email', 'login') NOT NULL DEFAULT 'register'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('otp_codes') && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE otp_codes MODIFY purpose ENUM('register', 'login', 'reset_password', 'verify_phone') NOT NULL DEFAULT 'register'");
        }
    }
};

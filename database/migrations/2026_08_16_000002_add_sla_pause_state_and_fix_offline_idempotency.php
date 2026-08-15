<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table): void {
            $table->timestamp('sla_paused_at')->nullable()->after('due_at');
            $table->unsignedBigInteger('sla_total_paused_seconds')->default(0)->after('sla_paused_at');
        });

        Schema::table('offline_submissions', function (Blueprint $table): void {
            $table->dropUnique(['client_uuid']);
            $table->unique(['citizen_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('offline_submissions', function (Blueprint $table): void {
            $table->dropUnique(['citizen_id', 'client_uuid']);
            $table->unique('client_uuid');
        });

        Schema::table('complaints', function (Blueprint $table): void {
            $table->dropColumn(['sla_paused_at', 'sla_total_paused_seconds']);
        });
    }
};

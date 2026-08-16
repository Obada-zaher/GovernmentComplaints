<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaint_information_requests', function (Blueprint $table): void {
            $table->text('response_message')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('complaint_information_requests', function (Blueprint $table): void {
            $table->dropColumn('response_message');
        });
    }
};

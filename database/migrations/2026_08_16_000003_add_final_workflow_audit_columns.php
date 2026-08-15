<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table): void {
            $table->timestamp('status_entered_at')->nullable()->after('status');
            $table->boolean('classification_auto_assigned')->default(false)->after('classification_confidence');
        });

        DB::table('complaints')
            ->whereNull('status_entered_at')
            ->update(['status_entered_at' => DB::raw('COALESCE(created_at, updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table): void {
            $table->dropColumn(['status_entered_at', 'classification_auto_assigned']);
        });
    }
};

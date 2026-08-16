<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('sla_rules')
            ->whereNull('deleted_at')
            ->select('department_id', 'category_id', 'priority_id')
            ->selectRaw('COUNT(*) as duplicate_count')
            ->groupBy('department_id', 'category_id', 'priority_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'Cannot add the SLA rule live-scope uniqueness constraint because duplicate active scopes exist. Resolve them manually before migrating.',
            );
        }

        Schema::table('sla_rules', function (Blueprint $table): void {
            $table->unsignedBigInteger('department_scope_key')
                ->virtualAs('COALESCE(department_id, 0)');
            $table->unsignedBigInteger('category_scope_key')
                ->virtualAs('COALESCE(category_id, 0)');
            $table->unsignedBigInteger('live_scope_key')
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN 0 ELSE id END');
            $table->unique([
                'department_scope_key',
                'category_scope_key',
                'priority_id',
                'live_scope_key',
            ], 'sla_rules_unique_live_scope');
        });
    }

    public function down(): void
    {
        Schema::table('sla_rules', function (Blueprint $table): void {
            $table->dropUnique('sla_rules_unique_live_scope');
            $table->dropColumn([
                'department_scope_key',
                'category_scope_key',
                'live_scope_key',
            ]);
        });
    }
};

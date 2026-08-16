<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, string> */
    private const HELPER_COLUMNS = [
        'department_scope_key',
        'category_scope_key',
        'priority_scope_key',
        'live_scope_key',
    ];

    public function up(): void
    {
        $duplicates = DB::table('sla_rules')
            ->whereNull('deleted_at')
            ->select('department_id', 'category_id', 'priority_id')
            ->selectRaw('COUNT(*) as duplicate_count')
            ->groupBy('department_id', 'category_id', 'priority_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $scopes = $duplicates
                ->map(fn (object $scope): string => sprintf(
                    '(department_id=%s, category_id=%s, priority_id=%s, count=%s)',
                    $scope->department_id ?? 'NULL',
                    $scope->category_id ?? 'NULL',
                    $scope->priority_id,
                    $scope->duplicate_count,
                ))
                ->implode(', ');

            throw new RuntimeException(
                "Cannot add the SLA rule live-scope uniqueness constraint because duplicate non-deleted scopes exist: {$scopes}. Resolve them manually before migrating.",
            );
        }

        $this->dropUniqueIndexIfPresent();
        $this->dropHelperColumnsIfPresent();

        Schema::table('sla_rules', function (Blueprint $table): void {
            $table->unsignedBigInteger('department_scope_key')
                ->nullable()
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN COALESCE(department_id, 0) ELSE NULL END');
            $table->unsignedBigInteger('category_scope_key')
                ->nullable()
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN COALESCE(category_id, 0) ELSE NULL END');
            $table->unsignedBigInteger('priority_scope_key')
                ->nullable()
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN priority_id ELSE NULL END');
            $table->unique([
                'department_scope_key',
                'category_scope_key',
                'priority_scope_key',
            ], 'sla_rules_unique_live_scope');
        });
    }

    public function down(): void
    {
        $this->dropUniqueIndexIfPresent();
        $this->dropHelperColumnsIfPresent();
    }

    private function dropUniqueIndexIfPresent(): void
    {
        if (! Schema::hasIndex('sla_rules', 'sla_rules_unique_live_scope')) {
            return;
        }

        Schema::table('sla_rules', function (Blueprint $table): void {
            $table->dropUnique('sla_rules_unique_live_scope');
        });
    }

    private function dropHelperColumnsIfPresent(): void
    {
        $columns = array_values(array_filter(
            self::HELPER_COLUMNS,
            fn (string $column): bool => Schema::hasColumn('sla_rules', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('sla_rules', function (Blueprint $table) use ($columns): void {
            $table->dropColumn([
                ...$columns,
            ]);
        });
    }
};

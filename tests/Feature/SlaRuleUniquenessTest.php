<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\SlaRule;
use App\Models\User;
use App\Services\Sla\SlaDeadlineService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SlaRuleUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_rule_can_be_created_and_exact_duplicate_is_rejected(): void
    {
        [$department, $category, $priority] = $this->scope();
        Sanctum::actingAs(User::factory()->admin()->create());
        $payload = $this->payload($department, $category, $priority);

        $this->postJson('/api/v1/admin/sla-rules', $payload)->assertCreated();
        $this->postJson('/api/v1/admin/sla-rules', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.priority_id.0', 'An SLA rule already exists for the selected department, category, and priority.');

        $this->assertSame(1, SlaRule::query()
            ->where('department_id', $department->id)
            ->where('category_id', $category->id)
            ->where('priority_id', $priority->id)
            ->count());
    }

    public function test_different_priority_category_and_department_scopes_are_allowed(): void
    {
        [$department, $category, $priority] = $this->scope();
        $secondPriority = Priority::factory()->create();
        $secondCategory = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $secondDepartment = Department::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/sla-rules', $this->payload($department, $category, $priority))->assertCreated();
        $this->postJson('/api/v1/admin/sla-rules', $this->payload($department, $category, $secondPriority))->assertCreated();
        $this->postJson('/api/v1/admin/sla-rules', $this->payload($department, $secondCategory, $priority))->assertCreated();
        $this->postJson('/api/v1/admin/sla-rules', $this->payload($secondDepartment, $category, $priority))->assertCreated();

        $this->assertSame(4, SlaRule::query()->count());
    }

    public function test_update_allows_the_same_scope_but_rejects_a_scope_collision(): void
    {
        [$department, $category, $priority] = $this->scope();
        $secondCategory = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $firstRule = SlaRule::factory()->create($this->scopeAttributes($department, $category, $priority));
        $secondRule = SlaRule::factory()->create($this->scopeAttributes($department, $secondCategory, $priority));
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/v1/admin/sla-rules/'.$firstRule->id, [
            'resolution_time_hours' => 96,
        ])->assertOk()
            ->assertJsonPath('data.sla_rule.resolution_time_hours', 96);
        $this->putJson('/api/v1/admin/sla-rules/'.$secondRule->id, [
            'category_id' => $category->id,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.priority_id.0', 'An SLA rule already exists for the selected department, category, and priority.');

        $this->assertSame($secondCategory->id, $secondRule->fresh()->category_id);
    }

    public function test_database_constraint_blocks_live_duplicate_exact_and_fallback_scopes_when_validation_is_bypassed(): void
    {
        [$department, $category, $priority] = $this->scope();

        foreach ([
            [$department->id, $category->id],
            [null, null],
            [$department->id, null],
            [null, $category->id],
        ] as [$departmentId, $categoryId]) {
            SlaRule::factory()->create([
                'department_id' => $departmentId,
                'category_id' => $categoryId,
                'priority_id' => $priority->id,
            ]);

            $this->assertLiveScopeDuplicateIsRejected($departmentId, $categoryId, $priority->id);
        }
    }

    public function test_soft_deleted_rules_do_not_block_recreating_the_same_scope(): void
    {
        [$department, $category, $priority] = $this->scope();
        $attributes = $this->scopeAttributes($department, $category, $priority);

        $firstRule = SlaRule::factory()->create($attributes);
        $firstRule->delete();

        $replacement = SlaRule::factory()->create($attributes);
        $replacement->delete();

        $currentRule = SlaRule::factory()->create($attributes);

        $this->assertSame(3, SlaRule::withTrashed()->where($attributes)->count());
        $this->assertSame($currentRule->id, SlaRule::query()->where($attributes)->sole()->id);
    }

    public function test_pending_migration_recovers_from_old_partial_helper_columns(): void
    {
        $migration = require database_path('migrations/2026_08_16_000006_add_live_scope_uniqueness_to_sla_rules_table.php');
        $migration->down();

        Schema::table('sla_rules', function (Blueprint $table): void {
            $table->unsignedBigInteger('department_scope_key')->virtualAs('COALESCE(department_id, 0)');
            $table->unsignedBigInteger('category_scope_key')->virtualAs('COALESCE(category_id, 0)');
        });

        $migration->up();

        $this->assertTrue(Schema::hasColumn('sla_rules', 'department_scope_key'));
        $this->assertTrue(Schema::hasColumn('sla_rules', 'category_scope_key'));
        $this->assertTrue(Schema::hasColumn('sla_rules', 'priority_scope_key'));
        $this->assertFalse(Schema::hasColumn('sla_rules', 'live_scope_key'));
        $this->assertTrue(Schema::hasIndex('sla_rules', 'sla_rules_unique_live_scope'));
    }

    public function test_resolver_keeps_exact_then_department_category_and_global_fallback_precedence(): void
    {
        [$department, $category, $priority] = $this->scope();
        $secondDepartment = Department::factory()->create();
        $secondCategory = ComplaintCategory::factory()->create(['department_id' => $secondDepartment->id]);
        $global = SlaRule::factory()->create([
            'department_id' => null,
            'category_id' => null,
            'priority_id' => $priority->id,
        ]);
        $categoryFallback = SlaRule::factory()->create([
            'department_id' => null,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
        ]);
        $departmentFallback = SlaRule::factory()->create([
            'department_id' => $department->id,
            'category_id' => null,
            'priority_id' => $priority->id,
        ]);
        $exact = SlaRule::factory()->create($this->scopeAttributes($department, $category, $priority));
        $resolver = app(SlaDeadlineService::class);

        $this->assertSame($exact->id, $resolver->findRule($department->id, $category->id, $priority->id)?->id);
        $this->assertSame($departmentFallback->id, $resolver->findRule($department->id, $secondCategory->id, $priority->id)?->id);
        $this->assertSame($categoryFallback->id, $resolver->findRule($secondDepartment->id, $category->id, $priority->id)?->id);
        $this->assertSame($global->id, $resolver->findRule($secondDepartment->id, $secondCategory->id, $priority->id)?->id);
    }

    private function assertLiveScopeDuplicateIsRejected(?int $departmentId, ?int $categoryId, int $priorityId): void
    {
        try {
            SlaRule::factory()->create([
                'department_id' => $departmentId,
                'category_id' => $categoryId,
                'priority_id' => $priorityId,
            ]);

            $this->fail('The database allowed a duplicate live SLA scope.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_resolver_deadline_and_submission_timeline_remain_deterministic(): void
    {
        [$department, $category, $priority] = $this->scope();
        $rule = SlaRule::factory()->create(array_merge(
            $this->scopeAttributes($department, $category, $priority),
            ['resolution_time_hours' => 6],
        ));
        $citizen = User::factory()->citizen()->create();
        Sanctum::actingAs($citizen);

        $this->assertSame(
            $rule->id,
            app(SlaDeadlineService::class)->findRule($department->id, $category->id, $priority->id)?->id,
        );

        $this->postJson('/api/v1/citizen/complaints', [
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
            'title' => 'Street light is not working',
            'description' => 'The street light has been out for two days.',
        ])->assertCreated();

        $complaint = Complaint::query()->where('citizen_id', $citizen->id)->sole();
        $this->assertSame($complaint->created_at?->copy()->addHours(6)->timestamp, $complaint->due_at?->timestamp);
        $this->assertDatabaseHas('complaint_status_histories', [
            'complaint_id' => $complaint->id,
            'to_status' => 'submitted',
        ]);
    }

    /** @return array{0: Department, 1: ComplaintCategory, 2: Priority} */
    private function scope(): array
    {
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);

        return [$department, $category, Priority::factory()->create()];
    }

    /** @return array<string, int> */
    private function payload(Department $department, ComplaintCategory $category, Priority $priority): array
    {
        return array_merge($this->scopeAttributes($department, $category, $priority), [
            'response_time_hours' => 4,
            'resolution_time_hours' => 24,
            'is_active' => true,
        ]);
    }

    /** @return array<string, int> */
    private function scopeAttributes(Department $department, ComplaintCategory $category, Priority $priority): array
    {
        return [
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
        ];
    }
}

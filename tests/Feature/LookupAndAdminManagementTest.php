<?php

namespace Tests\Feature;

use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\SlaRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LookupAndAdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_anyone_can_fetch_active_departments(): void
    {
        Department::factory()->create(['name' => 'Active Department', 'is_active' => true]);
        Department::factory()->create(['name' => 'Inactive Department', 'is_active' => false]);

        $response = $this->getJson('/api/v1/lookups/departments');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.departments')
            ->assertJsonPath('data.departments.0.name', 'Active Department');
    }

    public function test_anyone_can_fetch_categories(): void
    {
        $department = Department::factory()->create(['is_active' => true]);
        ComplaintCategory::factory()->create(['department_id' => $department->id, 'is_active' => true]);

        $response = $this->getJson('/api/v1/lookups/categories');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonPath('data.categories.0.department.id', $department->id);
    }

    public function test_categories_can_be_filtered_by_department_id(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id, 'is_active' => true]);
        ComplaintCategory::factory()->create(['department_id' => $otherDepartment->id, 'is_active' => true]);

        $response = $this->getJson('/api/v1/lookups/categories?department_id='.$department->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonPath('data.categories.0.id', $category->id);
    }

    public function test_anyone_can_fetch_priorities(): void
    {
        Priority::factory()->count(2)->create();

        $this->getJson('/api/v1/lookups/priorities')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.priorities');
    }

    public function test_anyone_can_fetch_complaint_statuses(): void
    {
        $this->getJson('/api/v1/lookups/complaint-statuses')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.statuses.0', 'submitted')
            ->assertJsonPath('data.statuses.8', 'escalated');
    }

    public function test_admin_can_create_department(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/departments', [
            'name' => 'Civil Defense',
            'code' => 'civil-defense',
            'description' => 'Emergency services',
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.department.code', 'civil-defense');

        $this->assertDatabaseHas('departments', ['code' => 'civil-defense']);
    }

    public function test_admin_can_update_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create(['name' => 'Old Name']);

        $this->putJson('/api/v1/admin/departments/'.$department->id, [
            'name' => 'New Name',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.department.name', 'New Name')
            ->assertJsonPath('data.department.is_active', false);
    }

    public function test_admin_can_delete_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();

        $this->deleteJson('/api/v1/admin/departments/'.$department->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('departments', ['id' => $department->id]);
    }

    public function test_citizen_cannot_create_department(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());

        $this->postJson('/api/v1/admin/departments', [
            'name' => 'Blocked',
            'code' => 'blocked',
        ])->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_unauthenticated_user_cannot_create_department(): void
    {
        $this->postJson('/api/v1/admin/departments', [
            'name' => 'Blocked',
            'code' => 'blocked',
        ])->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_create_category(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();

        $response = $this->postJson('/api/v1/admin/categories', [
            'department_id' => $department->id,
            'name' => 'Noise Complaint',
            'code' => 'noise-complaint',
            'keywords' => ['noise', 'loud'],
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.category.department.id', $department->id)
            ->assertJsonPath('data.category.code', 'noise-complaint');
    }

    public function test_admin_can_filter_categories_by_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        ComplaintCategory::factory()->create(['department_id' => $otherDepartment->id]);

        $this->getJson('/api/v1/admin/categories?department_id='.$department->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonPath('data.categories.0.id', $category->id);
    }

    public function test_citizen_cannot_create_category(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        $department = Department::factory()->create();

        $this->postJson('/api/v1/admin/categories', [
            'department_id' => $department->id,
            'name' => 'Blocked',
            'code' => 'blocked-category',
        ])->assertForbidden();
    }

    public function test_admin_can_create_priority(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/priorities', [
            'name' => 'Critical',
            'code' => 'critical',
            'level' => 5,
            'color' => '#991b1b',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.priority.level', 5);
    }

    public function test_admin_can_update_priority(): void
    {
        $this->actingAsAdmin();
        $priority = Priority::factory()->create(['level' => 2]);

        $this->putJson('/api/v1/admin/priorities/'.$priority->id, [
            'level' => 4,
            'description' => 'Updated priority',
        ])->assertOk()
            ->assertJsonPath('data.priority.level', 4);
    }

    public function test_citizen_cannot_create_priority(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());

        $this->postJson('/api/v1/admin/priorities', [
            'name' => 'Blocked',
            'code' => 'blocked-priority',
            'level' => 1,
        ])->assertForbidden();
    }

    public function test_admin_can_create_sla_rule(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $priority = Priority::factory()->create();

        $response = $this->postJson('/api/v1/admin/sla-rules', [
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
            'response_time_hours' => 4,
            'resolution_time_hours' => 24,
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sla_rule.department.id', $department->id)
            ->assertJsonPath('data.sla_rule.category.id', $category->id)
            ->assertJsonPath('data.sla_rule.priority.id', $priority->id);
    }

    public function test_admin_can_list_sla_rules_with_relationships(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $priority = Priority::factory()->create();
        SlaRule::factory()->create([
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
        ]);

        $this->getJson('/api/v1/admin/sla-rules')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sla_rules.0.department.id', $department->id)
            ->assertJsonPath('data.sla_rules.0.category.id', $category->id)
            ->assertJsonPath('data.sla_rules.0.priority.id', $priority->id);
    }

    public function test_citizen_cannot_create_sla_rule(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        $priority = Priority::factory()->create();

        $this->postJson('/api/v1/admin/sla-rules', [
            'priority_id' => $priority->id,
            'response_time_hours' => 4,
            'resolution_time_hours' => 24,
        ])->assertForbidden();
    }

    public function test_all_checked_responses_contain_success_key(): void
    {
        $this->getJson('/api/v1/lookups/departments')
            ->assertOk()
            ->assertJsonStructure(['success']);

        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/departments')
            ->assertOk()
            ->assertJsonStructure(['success']);
    }

    public function test_pagination_meta_exists_for_admin_list_endpoints(): void
    {
        $this->actingAsAdmin();
        Department::factory()->count(2)->create();

        $this->getJson('/api/v1/admin/departments?per_page=1')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => ['departments'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.per_page', 1);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }
}

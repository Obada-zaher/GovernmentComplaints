<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\SlaRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminComplaintManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_list_all_complaints(): void
    {
        $this->actingAsAdmin();
        Complaint::factory()->count(2)->create();

        $this->getJson('/api/v1/admin/complaints')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.complaints');
    }

    public function test_admin_can_filter_complaints_by_status(): void
    {
        $this->actingAsAdmin();
        $complaint = Complaint::factory()->create(['status' => 'submitted']);
        Complaint::factory()->create(['status' => 'resolved']);

        $this->getJson('/api/v1/admin/complaints?status=submitted')
            ->assertOk()
            ->assertJsonCount(1, 'data.complaints')
            ->assertJsonPath('data.complaints.0.id', $complaint->id);
    }

    public function test_admin_can_show_any_complaint_with_timeline(): void
    {
        $this->actingAsAdmin();
        $complaint = Complaint::factory()->create();
        $complaint->statusHistories()->create([
            'changed_by' => $complaint->citizen_id,
            'from_status' => null,
            'to_status' => 'submitted',
            'note' => 'Complaint submitted by citizen',
        ]);

        $this->getJson('/api/v1/admin/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonPath('data.id', $complaint->id)
            ->assertJsonStructure(['data' => ['citizen', 'timeline', 'assignments']])
            ->assertJsonCount(1, 'data.timeline');
    }

    public function test_admin_can_assign_complaint_to_employee(): void
    {
        $admin = $this->actingAsAdmin();
        [$department, $category, $priority] = $this->setupLookups();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $complaint = $this->createComplaint($department, $category, $priority, ['status' => 'under_review']);

        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/assign', [
            'assigned_employee_id' => $employee->id,
            'note' => 'Assigned to municipality employee.',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assigned_employee.id', $employee->id);

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'assigned_employee_id' => $employee->id,
            'status' => 'assigned',
        ]);
        $this->assertDatabaseHas('complaint_assignments', [
            'complaint_id' => $complaint->id,
            'assigned_by' => $admin->id,
            'assigned_to' => $employee->id,
        ]);
    }

    public function test_assigning_complaint_creates_assignment_record(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->setupLookups();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $complaint = $this->createComplaint($department, $category, $priority);

        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/assign', [
            'assigned_employee_id' => $employee->id,
            'note' => 'Assignment record test.',
        ])->assertOk();

        $this->assertDatabaseHas('complaint_assignments', [
            'complaint_id' => $complaint->id,
            'assigned_to' => $employee->id,
            'department_id' => $department->id,
            'note' => 'Assignment record test.',
        ]);
    }

    public function test_assigning_complaint_moves_status_to_assigned_when_applicable(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->setupLookups();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $complaint = $this->createComplaint($department, $category, $priority, ['status' => 'submitted']);

        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/assign', [
            'assigned_employee_id' => $employee->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'assigned');
    }

    public function test_assigning_complaint_creates_timeline_record(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->setupLookups();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $complaint = $this->createComplaint($department, $category, $priority, ['status' => 'under_review']);

        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/assign', [
            'assigned_employee_id' => $employee->id,
            'note' => 'Assigned from test.',
        ])->assertOk();

        $this->assertDatabaseHas('complaint_status_histories', [
            'complaint_id' => $complaint->id,
            'from_status' => 'under_review',
            'to_status' => 'assigned',
            'note' => 'Assigned from test.',
        ]);
    }

    public function test_admin_can_reassign_complaint(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->setupLookups();
        $oldEmployee = User::factory()->employee()->create(['department_id' => $department->id]);
        $newEmployee = User::factory()->employee()->create(['department_id' => $department->id]);
        $complaint = $this->createComplaint($department, $category, $priority, [
            'assigned_employee_id' => $oldEmployee->id,
            'status' => 'assigned',
        ]);

        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/assign', [
            'assigned_employee_id' => $newEmployee->id,
        ])->assertOk()
            ->assertJsonPath('data.assigned_employee.id', $newEmployee->id);

        $this->assertSame(1, $complaint->assignments()->count());
    }

    public function test_admin_cannot_assign_complaint_to_citizen(): void
    {
        $this->actingAsAdmin();
        $citizen = User::factory()->citizen()->create();
        $complaint = Complaint::factory()->create();

        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/assign', [
            'assigned_employee_id' => $citizen->id,
        ])->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_admin_cannot_assign_to_employee_from_different_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $employee = User::factory()->employee()->create(['department_id' => $otherDepartment->id]);
        $complaint = Complaint::factory()->create(['department_id' => $department->id]);

        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/assign', [
            'assigned_employee_id' => $employee->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_employee_id']);
    }

    public function test_admin_can_change_complaint_department(): void
    {
        $this->actingAsAdmin();
        $newDepartment = Department::factory()->create();
        $newCategory = ComplaintCategory::factory()->create(['department_id' => $newDepartment->id]);
        $complaint = Complaint::factory()->create();

        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/department', [
            'department_id' => $newDepartment->id,
            'category_id' => $newCategory->id,
            'note' => 'Corrected department based on complaint content.',
        ])->assertOk()
            ->assertJsonPath('data.department.id', $newDepartment->id)
            ->assertJsonPath('data.category.id', $newCategory->id);
    }

    public function test_changing_department_clears_assigned_employee_if_department_mismatch(): void
    {
        $this->actingAsAdmin();
        $oldDepartment = Department::factory()->create();
        $newDepartment = Department::factory()->create();
        $employee = User::factory()->employee()->create(['department_id' => $oldDepartment->id]);
        $complaint = Complaint::factory()->create([
            'department_id' => $oldDepartment->id,
            'assigned_employee_id' => $employee->id,
        ]);

        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/department', [
            'department_id' => $newDepartment->id,
        ])->assertOk()
            ->assertJsonPath('data.assigned_employee', null);

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'assigned_employee_id' => null,
        ]);
    }

    public function test_admin_can_change_priority(): void
    {
        $this->actingAsAdmin();
        $priority = Priority::factory()->create();
        $complaint = Complaint::factory()->create();

        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/priority', [
            'priority_id' => $priority->id,
            'note' => 'Priority increased.',
        ])->assertOk()
            ->assertJsonPath('data.priority.id', $priority->id);
    }

    public function test_changing_priority_recalculates_due_at_when_sla_exists(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00'));
        $this->actingAsAdmin();
        [$department, $category] = $this->setupLookups();
        $priority = Priority::factory()->create();
        $complaint = $this->createComplaint($department, $category, Priority::factory()->create());
        SlaRule::factory()->create([
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
            'resolution_time_hours' => 12,
            'is_active' => true,
        ]);

        $response = $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/priority', [
            'priority_id' => $priority->id,
        ])->assertOk();

        $this->assertSame(
            now()->addHours(12)->timestamp,
            Carbon::parse($response->json('data.due_at'))->timestamp,
        );
    }

    public function test_admin_can_update_status_with_valid_transition(): void
    {
        $this->actingAsAdmin();
        $complaint = Complaint::factory()->create(['status' => 'submitted']);

        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/status', [
            'status' => 'under_review',
            'note' => 'Admin started reviewing.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'under_review');
    }

    public function test_admin_cannot_update_status_with_invalid_transition(): void
    {
        $this->actingAsAdmin();
        $complaint = Complaint::factory()->create(['status' => 'submitted']);

        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/status', [
            'status' => 'resolved',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * @return array{0: Department, 1: ComplaintCategory, 2: Priority}
     */
    private function setupLookups(): array
    {
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $priority = Priority::factory()->create();

        return [$department, $category, $priority];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createComplaint(Department $department, ComplaintCategory $category, Priority $priority, array $overrides = []): Complaint
    {
        return Complaint::factory()->create(array_merge([
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
        ], $overrides));
    }
}

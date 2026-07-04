<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeComplaintProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_employee_can_list_complaints_assigned_to_him(): void
    {
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $assigned = $this->createComplaint($department, $category, $priority, ['assigned_employee_id' => $employee->id]);
        $otherEmployee = User::factory()->employee()->create(['department_id' => $department->id]);
        $this->createComplaint($department, $category, $priority, ['assigned_employee_id' => $otherEmployee->id]);

        $this->getJson('/api/v1/employee/complaints?scope=assigned_to_me')
            ->assertOk()
            ->assertJsonCount(1, 'data.complaints')
            ->assertJsonPath('data.complaints.0.id', $assigned->id);
    }

    public function test_employee_can_list_unassigned_complaints_in_his_department(): void
    {
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $unassigned = $this->createComplaint($department, $category, $priority, ['assigned_employee_id' => null]);
        $this->createComplaint($department, $category, $priority, ['assigned_employee_id' => $employee->id]);

        $this->getJson('/api/v1/employee/complaints?scope=my_department')
            ->assertOk()
            ->assertJsonCount(1, 'data.complaints')
            ->assertJsonPath('data.complaints.0.id', $unassigned->id);
    }

    public function test_employee_cannot_list_complaints_from_another_department(): void
    {
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $this->createComplaint($department, $category, $priority);
        $otherDepartment = Department::factory()->create();
        $otherCategory = ComplaintCategory::factory()->create(['department_id' => $otherDepartment->id]);
        $this->createComplaint($otherDepartment, $otherCategory, $priority);

        $this->getJson('/api/v1/employee/complaints')
            ->assertOk()
            ->assertJsonCount(1, 'data.complaints');
    }

    public function test_employee_can_show_accessible_complaint(): void
    {
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $complaint = $this->createComplaint($department, $category, $priority, ['assigned_employee_id' => $employee->id]);

        $this->getJson('/api/v1/employee/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonPath('data.id', $complaint->id)
            ->assertJsonStructure(['data' => ['citizen', 'timeline', 'assignments']]);
    }

    public function test_employee_cannot_show_inaccessible_complaint(): void
    {
        $this->actingAsEmployee();
        $otherDepartment = Department::factory()->create();
        $complaint = Complaint::factory()->create(['department_id' => $otherDepartment->id]);

        $this->getJson('/api/v1/employee/complaints/'.$complaint->id)
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_employee_can_update_status_with_valid_transition(): void
    {
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $complaint = $this->createComplaint($department, $category, $priority, [
            'assigned_employee_id' => $employee->id,
            'status' => 'assigned',
        ]);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'in_progress',
            'note' => 'Started processing.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_employee_cannot_update_status_with_invalid_transition(): void
    {
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $complaint = $this->createComplaint($department, $category, $priority, [
            'assigned_employee_id' => $employee->id,
            'status' => 'submitted',
        ]);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'resolved',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_employee_status_update_creates_timeline_record(): void
    {
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $complaint = $this->createComplaint($department, $category, $priority, [
            'assigned_employee_id' => $employee->id,
            'status' => 'assigned',
        ]);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'in_progress',
            'note' => 'Employee started processing.',
        ])->assertOk();

        $this->assertDatabaseHas('complaint_status_histories', [
            'complaint_id' => $complaint->id,
            'changed_by' => $employee->id,
            'from_status' => 'assigned',
            'to_status' => 'in_progress',
            'note' => 'Employee started processing.',
        ]);
    }

    public function test_first_response_at_is_set_on_first_employee_action(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00'));
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $complaint = $this->createComplaint($department, $category, $priority, [
            'assigned_employee_id' => $employee->id,
            'status' => 'assigned',
            'first_response_at' => null,
        ]);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'in_progress',
        ])->assertOk();

        $this->assertSame(now()->timestamp, $complaint->fresh()->first_response_at->timestamp);
    }

    public function test_resolved_at_is_set_when_status_becomes_resolved(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00'));
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $complaint = $this->createComplaint($department, $category, $priority, [
            'assigned_employee_id' => $employee->id,
            'status' => 'in_progress',
            'resolved_at' => null,
        ]);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'resolved',
        ])->assertOk();

        $this->assertSame(now()->timestamp, $complaint->fresh()->resolved_at->timestamp);
    }

    public function test_employee_cannot_close_complaint(): void
    {
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $complaint = $this->createComplaint($department, $category, $priority, [
            'assigned_employee_id' => $employee->id,
            'status' => 'resolved',
        ]);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'closed',
        ])->assertUnprocessable();
    }

    public function test_employee_cannot_reject_complaint(): void
    {
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $complaint = $this->createComplaint($department, $category, $priority, [
            'assigned_employee_id' => $employee->id,
            'status' => 'submitted',
        ]);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'rejected',
        ])->assertUnprocessable();
    }

    public function test_duration_minutes_is_calculated_in_timeline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00'));
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $complaint = $this->createComplaint($department, $category, $priority, [
            'assigned_employee_id' => $employee->id,
            'status' => 'assigned',
            'created_at' => now()->subMinutes(45),
        ]);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'in_progress',
        ])->assertOk();

        $this->assertDatabaseHas('complaint_status_histories', [
            'complaint_id' => $complaint->id,
            'from_status' => 'assigned',
            'to_status' => 'in_progress',
            'duration_minutes' => 45,
        ]);
    }

    public function test_employee_auto_assigns_unassigned_department_complaint_when_starting_progress(): void
    {
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $complaint = $this->createComplaint($department, $category, $priority, [
            'assigned_employee_id' => null,
            'status' => 'submitted',
        ]);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'in_progress',
            'note' => 'Started processing.',
        ])->assertOk()
            ->assertJsonPath('data.assigned_employee.id', $employee->id)
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('complaint_assignments', [
            'complaint_id' => $complaint->id,
            'assigned_by' => $employee->id,
            'assigned_to' => $employee->id,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_employee_or_admin_complaints(): void
    {
        $this->getJson('/api/v1/employee/complaints')->assertUnauthorized();
        $this->getJson('/api/v1/admin/complaints')->assertUnauthorized();
    }

    public function test_citizen_cannot_access_employee_or_admin_complaints(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());

        $this->getJson('/api/v1/employee/complaints')->assertForbidden();
        $this->getJson('/api/v1/admin/complaints')->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Department, 2: ComplaintCategory, 3: Priority}
     */
    private function actingAsEmployee(): array
    {
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $priority = Priority::factory()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        Sanctum::actingAs($employee);

        return [$employee, $department, $category, $priority];
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

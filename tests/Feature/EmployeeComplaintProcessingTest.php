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
        $complaint->citizen->forceFill(['name' => 'Citizen Detail User'])->save();
        $employee->forceFill(['name' => 'Government Employee'])->save();
        $complaint->attachments()->create([
            'uploaded_by' => $complaint->citizen_id,
            'original_name' => 'proof.jpg',
            'file_name' => 'proof.jpg',
            'file_path' => 'complaints/proof.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
            'disk' => 'public',
        ]);
        $complaint->statusHistories()->create([
            'changed_by' => $employee->id,
            'from_status' => 'submitted',
            'to_status' => 'under_review',
            'note' => 'Employee review',
        ]);

        $response = $this->getJson('/api/v1/employee/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonPath('data.id', $complaint->id)
            ->assertJsonStructure(['data' => ['citizen', 'timeline', 'assignments']])
            ->assertJsonPath('data.attachments.0.uploaded_by', 'Citizen Detail User')
            ->assertJsonPath('data.timeline.0.changed_by', 'Government Employee');

        $this->assertIsString($response->json('data.attachments.0.uploaded_by'));
        $this->assertIsNotArray($response->json('data.attachments.0.uploaded_by'));
        $this->assertIsString($response->json('data.timeline.0.changed_by'));
        $this->assertIsNotArray($response->json('data.timeline.0.changed_by'));
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

    public function test_employee_cannot_access_or_update_a_coworkers_assigned_complaint(): void
    {
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $coworker = User::factory()->employee()->create(['department_id' => $department->id]);
        $complaint = $this->createComplaint($department, $category, $priority, [
            'assigned_employee_id' => $coworker->id,
            'status' => 'assigned',
        ]);

        $this->getJson('/api/v1/employee/complaints')
            ->assertOk()
            ->assertJsonCount(0, 'data.complaints');
        $this->getJson('/api/v1/employee/complaints/'.$complaint->id)->assertForbidden();
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'in_progress',
        ])->assertForbidden();
    }

    public function test_employee_without_a_department_only_sees_complaints_assigned_directly_to_them(): void
    {
        $employee = User::factory()->employee()->create(['department_id' => null]);
        Sanctum::actingAs($employee);
        $unassignedWithoutDepartment = Complaint::factory()->create([
            'department_id' => null,
            'assigned_employee_id' => null,
        ]);
        $assignedToEmployee = Complaint::factory()->create([
            'department_id' => null,
            'assigned_employee_id' => $employee->id,
        ]);

        $this->getJson('/api/v1/employee/complaints')
            ->assertOk()
            ->assertJsonCount(1, 'data.complaints')
            ->assertJsonPath('data.complaints.0.id', $assignedToEmployee->id);
        $this->getJson('/api/v1/employee/complaints?scope=my_department')
            ->assertOk()
            ->assertJsonCount(0, 'data.complaints');
        $this->getJson('/api/v1/employee/complaints/'.$unassignedWithoutDepartment->id)
            ->assertForbidden();
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

    public function test_employee_cannot_start_a_submitted_complaint_directly(): void
    {
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $complaint = $this->createComplaint($department, $category, $priority, [
            'assigned_employee_id' => null,
            'status' => 'submitted',
        ]);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'in_progress',
            'note' => 'Started processing.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => 'submitted',
            'assigned_employee_id' => null,
        ]);
        $this->assertSame(0, $complaint->assignments()->count());
    }

    public function test_employee_can_acquire_an_unassigned_under_review_complaint_when_starting_progress(): void
    {
        [$employee, $department, $category, $priority] = $this->actingAsEmployee();
        $complaint = $this->createComplaint($department, $category, $priority, [
            'assigned_employee_id' => null,
            'status' => 'under_review',
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
        $this->assertDatabaseHas('complaint_status_histories', [
            'complaint_id' => $complaint->id,
            'from_status' => 'under_review',
            'to_status' => 'assigned',
        ]);
    }

    public function test_only_one_employee_can_acquire_an_unassigned_under_review_complaint(): void
    {
        [$firstEmployee, $department, $category, $priority] = $this->actingAsEmployee();
        $secondEmployee = User::factory()->employee()->create(['department_id' => $department->id]);
        $complaint = $this->createComplaint($department, $category, $priority, [
            'assigned_employee_id' => null,
            'status' => 'under_review',
        ]);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'in_progress',
        ])->assertOk();

        Sanctum::actingAs($secondEmployee);
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'in_progress',
        ])->assertForbidden();

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'assigned_employee_id' => $firstEmployee->id,
            'status' => 'in_progress',
        ]);
        $this->assertSame(1, $complaint->assignments()->count());
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

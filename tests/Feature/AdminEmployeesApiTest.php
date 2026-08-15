<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminEmployeesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_list_employees(): void
    {
        $this->getJson('/api/v1/admin/employees')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_citizen_cannot_list_employees(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());

        $this->getJson('/api/v1/admin/employees')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_employee_cannot_list_employees(): void
    {
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->getJson('/api/v1/admin/employees')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_list_only_employees_with_their_departments(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create(['name' => 'Municipality']);
        $firstEmployee = User::factory()->employee()->create([
            'name' => 'Adam Employee',
            'department_id' => $department->id,
        ]);
        $secondEmployee = User::factory()->employee()->create(['name' => 'Bella Employee']);
        $citizen = User::factory()->citizen()->create();
        $otherAdmin = User::factory()->admin()->create();

        $response = $this->getJson('/api/v1/admin/employees')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Employees retrieved successfully.')
            ->assertJsonCount(2, 'data.employees')
            ->assertJsonPath('data.employees.0.id', $firstEmployee->id)
            ->assertJsonPath('data.employees.0.department.id', $department->id)
            ->assertJsonPath('data.employees.0.department.name', 'Municipality');

        $employeeIds = collect($response->json('data.employees'))->pluck('id')->all();

        $this->assertContains($secondEmployee->id, $employeeIds);
        $this->assertNotContains($citizen->id, $employeeIds);
        $this->assertNotContains($otherAdmin->id, $employeeIds);
    }

    public function test_admin_can_filter_employees_by_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        User::factory()->employee()->create(['department_id' => $otherDepartment->id]);

        $this->getJson('/api/v1/admin/employees?department_id='.$department->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.employees')
            ->assertJsonPath('data.employees.0.id', $employee->id);
    }

    public function test_admin_can_filter_employees_by_active_status(): void
    {
        $this->actingAsAdmin();
        $activeEmployee = User::factory()->employee()->create(['name' => 'Active Employee', 'is_active' => true]);
        $inactiveEmployee = User::factory()->employee()->create(['name' => 'Inactive Employee', 'is_active' => false]);

        $this->getJson('/api/v1/admin/employees?is_active=true')
            ->assertOk()
            ->assertJsonCount(1, 'data.employees')
            ->assertJsonPath('data.employees.0.id', $activeEmployee->id);

        $this->getJson('/api/v1/admin/employees?is_active=false')
            ->assertOk()
            ->assertJsonCount(1, 'data.employees')
            ->assertJsonPath('data.employees.0.id', $inactiveEmployee->id);

        $this->getJson('/api/v1/admin/employees')
            ->assertOk()
            ->assertJsonCount(2, 'data.employees');
    }

    public function test_admin_can_search_employees_by_name_email_and_phone(): void
    {
        $this->actingAsAdmin();
        $employee = User::factory()->employee()->create([
            'name' => 'Ahmad Al-Hassan',
            'email' => 'ahmad.employee@example.test',
            'phone' => '0991234567',
        ]);
        User::factory()->employee()->create([
            'name' => 'Unrelated Employee',
            'email' => 'unrelated@example.test',
            'phone' => '0997654321',
        ]);

        foreach (['Ahmad', 'ahmad.employee@example.test', '0991234567'] as $search) {
            $this->getJson('/api/v1/admin/employees?search='.urlencode($search))
                ->assertOk()
                ->assertJsonCount(1, 'data.employees')
                ->assertJsonPath('data.employees.0.id', $employee->id);
        }
    }

    public function test_employee_listing_is_paginated_and_per_page_is_capped(): void
    {
        $this->actingAsAdmin();
        User::factory()->employee()->count(101)->create();

        $this->getJson('/api/v1/admin/employees?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 101)
            ->assertJsonPath('meta.last_page', 51)
            ->assertJsonPath('meta.from', 3)
            ->assertJsonPath('meta.to', 4);

        $this->getJson('/api/v1/admin/employees?per_page=1000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_employee_listing_does_not_expose_sensitive_attributes(): void
    {
        $this->actingAsAdmin();
        User::factory()->employee()->create();

        $this->getJson('/api/v1/admin/employees')
            ->assertOk()
            ->assertJsonMissingPath('data.employees.0.password')
            ->assertJsonMissingPath('data.employees.0.remember_token')
            ->assertJsonMissingPath('data.employees.0.otp_codes');
    }

    public function test_listed_employee_can_be_assigned_to_a_complaint_in_the_same_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $complaint = Complaint::factory()->create([
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => Priority::factory()->create()->id,
            'status' => 'under_review',
        ]);

        $employeeId = $this->getJson('/api/v1/admin/employees?department_id='.$department->id)
            ->assertOk()
            ->json('data.employees.0.id');

        $this->assertSame($employee->id, $employeeId);

        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/assign', [
            'assigned_employee_id' => $employeeId,
        ])->assertOk()
            ->assertJsonPath('data.assigned_employee.id', $employee->id);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }
}

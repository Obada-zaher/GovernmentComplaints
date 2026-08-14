<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_admin_user_endpoints(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->postJson('/api/v1/admin/users', $this->userPayload())->assertUnauthorized();
    }

    public function test_citizen_and_employee_cannot_access_admin_user_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        $this->getJson('/api/v1/admin/users')->assertForbidden();

        Sanctum::actingAs(User::factory()->employee()->create());
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_admin_can_list_all_roles_with_department_data(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create(['name' => 'Municipality']);
        $citizen = User::factory()->citizen()->create(['name' => 'Citizen User']);
        $employee = User::factory()->employee()->create(['name' => 'Employee User', 'department_id' => $department->id]);
        $otherAdmin = User::factory()->admin()->create(['name' => 'Other Admin']);

        $response = $this->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Users retrieved successfully.');

        $users = collect($response->json('data.users'));

        $this->assertTrue($users->pluck('id')->contains($citizen->id));
        $this->assertTrue($users->pluck('id')->contains($employee->id));
        $this->assertTrue($users->pluck('id')->contains($otherAdmin->id));
        $this->assertSame($department->id, $users->firstWhere('id', $employee->id)['department']['id']);
    }

    public function test_admin_can_filter_users_by_role_department_active_status_and_search(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();
        $employee = User::factory()->employee()->create([
            'name' => 'Ahmad Employee',
            'email' => 'ahmad@example.test',
            'phone' => '0991234567',
            'national_id' => '12345678901',
            'department_id' => $department->id,
            'is_active' => true,
        ]);
        User::factory()->employee()->create(['department_id' => Department::factory()->create()->id, 'is_active' => false]);
        User::factory()->citizen()->create();

        $this->getJson('/api/v1/admin/users?role=employee')
            ->assertOk()
            ->assertJsonCount(2, 'data.users');
        $this->getJson('/api/v1/admin/users?department_id='.$department->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.id', $employee->id);
        $this->getJson('/api/v1/admin/users?is_active=false')
            ->assertOk()
            ->assertJsonCount(1, 'data.users');

        foreach (['Ahmad', 'ahmad@example.test', '0991234567', '12345678901'] as $search) {
            $this->getJson('/api/v1/admin/users?search='.urlencode($search))
                ->assertOk()
                ->assertJsonCount(1, 'data.users')
                ->assertJsonPath('data.users.0.id', $employee->id);
        }
    }

    public function test_admin_user_list_is_paginated_capped_and_does_not_expose_sensitive_fields(): void
    {
        $this->actingAsAdmin();
        User::factory()->citizen()->count(101)->create();

        $this->getJson('/api/v1/admin/users?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 102)
            ->assertJsonPath('meta.last_page', 51)
            ->assertJsonPath('meta.from', 3)
            ->assertJsonPath('meta.to', 4)
            ->assertJsonMissingPath('data.users.0.password')
            ->assertJsonMissingPath('data.users.0.remember_token')
            ->assertJsonMissingPath('data.users.0.otp_codes');

        $this->getJson('/api/v1/admin/users?per_page=1000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_admin_can_create_an_employee_with_an_active_department_and_hashed_password(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();

        $response = $this->postJson('/api/v1/admin/users', $this->userPayload([
            'role' => 'employee',
            'department_id' => $department->id,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.user.role', 'employee')
            ->assertJsonPath('data.user.department.id', $department->id)
            ->assertJsonMissingPath('data.user.password');

        $user = User::query()->where('email', 'new.user@example.test')->firstOrFail();
        $this->assertSame($department->id, $user->department_id);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertNull($user->email_verified_at);
    }

    public function test_admin_can_create_citizen_and_admin_without_departments(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();

        $this->postJson('/api/v1/admin/users', $this->userPayload([
            'role' => 'citizen',
            'department_id' => $department->id,
        ]))->assertCreated()
            ->assertJsonPath('data.user.department', null);

        $this->postJson('/api/v1/admin/users', $this->userPayload([
            'email' => 'new.admin@example.test',
            'phone' => '0990000002',
            'national_id' => '12345678902',
            'role' => 'admin',
            'department_id' => $department->id,
        ]))->assertCreated()
            ->assertJsonPath('data.user.department', null);

        $this->assertDatabaseHas('users', ['email' => 'new.user@example.test', 'department_id' => null]);
        $this->assertDatabaseHas('users', ['email' => 'new.admin@example.test', 'department_id' => null]);
    }

    public function test_admin_cannot_create_employee_without_an_active_valid_department(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/users', $this->userPayload(['role' => 'employee']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);
        $this->postJson('/api/v1/admin/users', $this->userPayload(['role' => 'employee', 'department_id' => 999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);

        $inactiveDepartment = Department::factory()->create(['is_active' => false]);
        $this->postJson('/api/v1/admin/users', $this->userPayload(['role' => 'employee', 'department_id' => $inactiveDepartment->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);
    }

    public function test_admin_cannot_create_users_with_duplicate_email_or_phone(): void
    {
        $this->actingAsAdmin();
        $existing = User::factory()->create(['email' => 'existing@example.test', 'phone' => '0990000009']);

        $this->postJson('/api/v1/admin/users', $this->userPayload(['email' => $existing->email]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
        $this->postJson('/api/v1/admin/users', $this->userPayload(['phone' => $existing->phone]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_admin_can_show_a_user_with_department_information(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);

        $this->getJson('/api/v1/admin/users/'.$employee->id)
            ->assertOk()
            ->assertJsonPath('data.user.id', $employee->id)
            ->assertJsonPath('data.user.department.id', $department->id)
            ->assertJsonMissingPath('data.user.password');
    }

    public function test_admin_can_update_an_employee_and_change_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();
        $newDepartment = Department::factory()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);

        $this->patchJson('/api/v1/admin/users/'.$employee->id, [
            'name' => 'Updated Employee',
            'department_id' => $newDepartment->id,
        ])->assertOk()
            ->assertJsonPath('data.user.name', 'Updated Employee')
            ->assertJsonPath('data.user.department.id', $newDepartment->id);

        $this->assertDatabaseHas('users', ['id' => $employee->id, 'department_id' => $newDepartment->id]);
    }

    public function test_role_changes_enforce_and_normalize_department_assignment(): void
    {
        $this->actingAsAdmin();
        $department = Department::factory()->create();
        $citizen = User::factory()->citizen()->create();

        $this->patchJson('/api/v1/admin/users/'.$citizen->id, ['role' => 'employee'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);

        $this->patchJson('/api/v1/admin/users/'.$citizen->id, [
            'role' => 'employee',
            'department_id' => $department->id,
        ])->assertOk()
            ->assertJsonPath('data.user.role', 'employee')
            ->assertJsonPath('data.user.department.id', $department->id);

        $this->patchJson('/api/v1/admin/users/'.$citizen->id, ['role' => 'citizen'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'citizen')
            ->assertJsonPath('data.user.department', null);

        $this->patchJson('/api/v1/admin/users/'.$citizen->id, [
            'role' => 'employee',
            'department_id' => $department->id,
        ])->assertOk();

        $this->patchJson('/api/v1/admin/users/'.$citizen->id, ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonPath('data.user.department', null);
    }

    public function test_admin_user_update_rejects_invalid_role_and_preserves_unique_values_for_current_user(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->citizen()->create();
        $otherUser = User::factory()->citizen()->create();

        $this->patchJson('/api/v1/admin/users/'.$user->id, [
            'email' => $user->email,
            'phone' => $user->phone,
            'national_id' => $user->national_id,
        ])->assertOk();

        $this->patchJson('/api/v1/admin/users/'.$user->id, ['role' => 'manager'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
        $this->patchJson('/api/v1/admin/users/'.$user->id, ['email' => $otherUser->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_existing_admin_employees_endpoint_remains_compatible(): void
    {
        $this->actingAsAdmin();
        $employee = User::factory()->employee()->create();
        User::factory()->citizen()->create();

        $this->getJson('/api/v1/admin/employees')
            ->assertOk()
            ->assertJsonPath('data.employees.0.id', $employee->id)
            ->assertJsonPath('data.employees.0.role', 'employee');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function userPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New User',
            'email' => 'new.user@example.test',
            'phone' => '0990000001',
            'national_id' => '12345678901',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'citizen',
            'is_active' => true,
        ], $overrides);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }
}

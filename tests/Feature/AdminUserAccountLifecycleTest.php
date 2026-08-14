<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_citizen_and_employee_cannot_update_a_user_status(): void
    {
        $target = User::factory()->citizen()->create();

        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['is_active' => false])
            ->assertUnauthorized();

        Sanctum::actingAs(User::factory()->citizen()->create());
        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['is_active' => false])
            ->assertForbidden();

        Sanctum::actingAs(User::factory()->employee()->create());
        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['is_active' => false])
            ->assertForbidden();
    }

    public function test_admin_can_deactivate_and_reactivate_other_users_without_exposing_sensitive_data(): void
    {
        $this->actingAsAdmin();
        $citizen = User::factory()->citizen()->create();
        $department = Department::factory()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);

        $this->patchJson('/api/v1/admin/users/'.$citizen->id.'/status', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('message', 'User status updated successfully.')
            ->assertJsonPath('data.user.id', $citizen->id)
            ->assertJsonPath('data.user.is_active', false)
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');
        $this->patchJson('/api/v1/admin/users/'.$employee->id.'/status', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.user.is_active', false);

        $this->patchJson('/api/v1/admin/users/'.$citizen->id.'/status', ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.user.is_active', true);

        $this->assertDatabaseHas('users', ['id' => $citizen->id, 'is_active' => true]);
        $this->assertDatabaseHas('users', ['id' => $employee->id, 'is_active' => false]);
    }

    public function test_status_update_validates_is_active_and_general_update_cannot_change_it(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->citizen()->create(['is_active' => true]);

        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['is_active' => 'yes'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);
        $this->patchJson('/api/v1/admin/users/'.$target->id, ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
    }

    public function test_deactivation_revokes_all_tokens_and_reactivation_does_not_restore_them(): void
    {
        $admin = $this->actingAsAdmin();
        $target = User::factory()->citizen()->create();
        $revokedToken = $target->createToken('mobile')->plainTextToken;
        $target->createToken('web');

        $this->assertSame(2, $target->tokens()->count());

        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['is_active' => false])
            ->assertOk();

        $this->assertSame(0, $target->fresh()->tokens()->count());
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$revokedToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['is_active' => true])
            ->assertOk();
        $this->assertSame(0, $target->fresh()->tokens()->count());
    }

    public function test_active_user_middleware_blocks_inactive_users_for_each_role_and_allows_active_users(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create(['is_active' => false]));
        $this->getJson('/api/v1/citizen/ping')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is inactive.');

        Sanctum::actingAs(User::factory()->employee()->create(['is_active' => false]));
        $this->getJson('/api/v1/employee/ping')->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create(['is_active' => false]));
        $this->getJson('/api/v1/admin/ping')->assertForbidden();

        Sanctum::actingAs(User::factory()->citizen()->create());
        $this->getJson('/api/v1/citizen/ping')->assertOk();
        Sanctum::actingAs(User::factory()->employee()->create());
        $this->getJson('/api/v1/employee/ping')->assertOk();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/ping')->assertOk();
    }

    public function test_admin_cannot_deactivate_or_demote_their_own_account(): void
    {
        $admin = $this->actingAsAdmin();

        $this->patchJson('/api/v1/admin/users/'.$admin->id.'/status', ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);
        $this->patchJson('/api/v1/admin/users/'.$admin->id, ['role' => 'citizen'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin', 'is_active' => true]);
    }

    public function test_last_active_admin_cannot_be_deactivated_or_demoted_and_inactive_admins_do_not_count(): void
    {
        $lastActiveAdmin = User::factory()->admin()->create();
        $inactiveAdmin = User::factory()->admin()->create(['is_active' => false]);
        $service = app(AdminUserService::class);

        try {
            $service->updateStatus($lastActiveAdmin, false, $inactiveAdmin);
            $this->fail('The final active admin was deactivated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role', $exception->errors());
        }

        try {
            $service->update($lastActiveAdmin, ['role' => 'citizen'], $inactiveAdmin);
            $this->fail('The final active admin was demoted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role', $exception->errors());
        }

        $this->assertDatabaseHas('users', ['id' => $lastActiveAdmin->id, 'role' => 'admin', 'is_active' => true]);
    }

    public function test_one_of_two_active_admins_can_deactivate_or_demote_the_other(): void
    {
        $this->actingAsAdmin();
        $otherAdmin = User::factory()->admin()->create();

        $this->patchJson('/api/v1/admin/users/'.$otherAdmin->id.'/status', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.user.is_active', false);

        $replacementAdmin = User::factory()->admin()->create();
        $this->patchJson('/api/v1/admin/users/'.$replacementAdmin->id, ['role' => 'citizen'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'citizen');
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }
}

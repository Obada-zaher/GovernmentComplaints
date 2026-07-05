<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\SlaRule;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Complaints\ComplaintEventNotification;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SlaAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_due_at_is_calculated_using_exact_sla_rule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00'));
        $this->actingAsCitizen();
        [$department, $category, $priority] = $this->lookups();
        SlaRule::factory()->create([
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
            'resolution_time_hours' => 10,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Exact SLA',
            'description' => 'Exact SLA rule should win.',
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
        ])->assertCreated();

        $this->assertSame(now()->addHours(10)->timestamp, Carbon::parse($response->json('data.due_at'))->timestamp);
    }

    public function test_due_at_is_calculated_using_priority_only_fallback(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00'));
        $this->actingAsCitizen();
        [$department, $category, $priority] = $this->lookups();
        SlaRule::factory()->create([
            'department_id' => null,
            'category_id' => null,
            'priority_id' => $priority->id,
            'resolution_time_hours' => 24,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Fallback SLA',
            'description' => 'Priority-only SLA rule should be used.',
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
        ])->assertCreated();

        $this->assertSame(now()->addHours(24)->timestamp, Carbon::parse($response->json('data.due_at'))->timestamp);
    }

    public function test_due_at_remains_null_when_no_sla_rule_exists(): void
    {
        $this->actingAsCitizen();
        [$department, $category, $priority] = $this->lookups();

        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'No SLA',
            'description' => 'No active SLA rule exists.',
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
        ])->assertCreated()
            ->assertJsonPath('data.due_at', null);
    }

    public function test_priority_change_recalculates_due_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00'));
        $this->actingAsAdmin();
        [$department, $category, $oldPriority] = $this->lookups();
        $newPriority = Priority::factory()->create();
        $complaint = $this->complaint($department, $category, $oldPriority);
        SlaRule::factory()->create([
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $newPriority->id,
            'resolution_time_hours' => 6,
            'is_active' => true,
        ]);

        $response = $this->patchJson("/api/v1/admin/complaints/{$complaint->id}/priority", [
            'priority_id' => $newPriority->id,
        ])->assertOk();

        $this->assertSame(now()->addHours(6)->timestamp, Carbon::parse($response->json('data.due_at'))->timestamp);
    }

    public function test_department_and_category_change_recalculates_due_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00'));
        $this->actingAsAdmin();
        [$oldDepartment, $oldCategory, $priority] = $this->lookups();
        $newDepartment = Department::factory()->create();
        $newCategory = ComplaintCategory::factory()->create(['department_id' => $newDepartment->id]);
        $complaint = $this->complaint($oldDepartment, $oldCategory, $priority);
        SlaRule::factory()->create([
            'department_id' => $newDepartment->id,
            'category_id' => $newCategory->id,
            'priority_id' => $priority->id,
            'resolution_time_hours' => 9,
            'is_active' => true,
        ]);

        $response = $this->patchJson("/api/v1/admin/complaints/{$complaint->id}/department", [
            'department_id' => $newDepartment->id,
            'category_id' => $newCategory->id,
        ])->assertOk();

        $this->assertSame(now()->addHours(9)->timestamp, Carbon::parse($response->json('data.due_at'))->timestamp);
    }

    public function test_sla_command_marks_overdue_complaint_as_breached(): void
    {
        [$department, $category, $priority] = $this->lookups();
        User::factory()->admin()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $complaint = $this->complaint($department, $category, $priority, [
            'assigned_employee_id' => $employee->id,
            'status' => 'in_progress',
            'due_at' => now()->subHour(),
        ]);

        $this->artisan('complaints:check-sla')
            ->expectsOutput('Checked complaints: 1')
            ->expectsOutput('Breached complaints: 1')
            ->expectsOutput('Notified users: 2')
            ->assertExitCode(0);

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'is_sla_breached' => true,
            'status' => 'escalated',
        ]);
        $this->assertDatabaseHas('complaint_status_histories', [
            'complaint_id' => $complaint->id,
            'changed_by' => null,
            'from_status' => 'in_progress',
            'to_status' => 'escalated',
            'note' => 'SLA breached automatically by system.',
        ]);
    }

    public function test_sla_command_does_not_mark_resolved_complaint_as_breached(): void
    {
        $complaint = Complaint::factory()->create([
            'status' => 'resolved',
            'due_at' => now()->subDay(),
            'is_sla_breached' => false,
        ]);

        $this->artisan('complaints:check-sla')->assertExitCode(0);

        $this->assertFalse($complaint->fresh()->is_sla_breached);
    }

    public function test_sla_command_does_not_mark_closed_complaint_as_breached(): void
    {
        $complaint = Complaint::factory()->create([
            'status' => 'closed',
            'due_at' => now()->subDay(),
            'is_sla_breached' => false,
        ]);

        $this->artisan('complaints:check-sla')->assertExitCode(0);

        $this->assertFalse($complaint->fresh()->is_sla_breached);
    }

    public function test_sla_command_is_idempotent_and_does_not_duplicate_notifications(): void
    {
        [$department, $category, $priority] = $this->lookups();
        User::factory()->admin()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $complaint = $this->complaint($department, $category, $priority, [
            'assigned_employee_id' => $employee->id,
            'status' => 'assigned',
            'due_at' => now()->subHour(),
        ]);

        $this->artisan('complaints:check-sla')->assertExitCode(0);
        $this->artisan('complaints:check-sla')
            ->expectsOutput('Checked complaints: 0')
            ->expectsOutput('Breached complaints: 0')
            ->assertExitCode(0);

        $this->assertSame(2, UserNotification::query()
            ->where('complaint_id', $complaint->id)
            ->where('type', NotificationService::TYPE_SLA_BREACHED)
            ->count());
    }

    public function test_notification_service_creates_record(): void
    {
        $user = User::factory()->citizen()->create();
        $complaint = Complaint::factory()->create(['citizen_id' => $user->id]);

        app(NotificationService::class)->notifyUser(
            $user,
            NotificationService::TYPE_COMPLAINT_STATUS_UPDATED,
            $complaint,
            'Status updated',
            'Complaint status changed.',
        );

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'complaint_id' => $complaint->id,
            'type' => NotificationService::TYPE_COMPLAINT_STATUS_UPDATED,
        ]);
    }

    public function test_user_can_list_own_notifications(): void
    {
        $user = $this->actingAsCitizen();
        $notification = UserNotification::factory()->create(['user_id' => $user->id, 'title' => 'Mine']);
        UserNotification::factory()->create(['title' => 'Other']);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.id', $notification->id);
    }

    public function test_user_cannot_read_another_users_notification(): void
    {
        $this->actingAsCitizen();
        $notification = UserNotification::factory()->create();

        $this->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_unread_count_works(): void
    {
        $user = $this->actingAsCitizen();
        UserNotification::factory()->count(2)->create(['user_id' => $user->id, 'read_at' => null]);
        UserNotification::factory()->create(['user_id' => $user->id, 'read_at' => now()]);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 2);
    }

    public function test_mark_notification_as_read_works(): void
    {
        $user = $this->actingAsCitizen();
        $notification = UserNotification::factory()->create(['user_id' => $user->id, 'read_at' => null]);

        $this->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_read_all_marks_current_user_notifications_as_read(): void
    {
        $user = $this->actingAsCitizen();
        UserNotification::factory()->count(2)->create(['user_id' => $user->id, 'read_at' => null]);
        UserNotification::factory()->create(['read_at' => null]);

        $this->patchJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 2);

        $this->assertSame(0, UserNotification::query()->where('user_id', $user->id)->whereNull('read_at')->count());
    }

    public function test_delete_own_notification_works(): void
    {
        $user = $this->actingAsCitizen();
        $notification = UserNotification::factory()->create(['user_id' => $user->id]);

        $this->deleteJson("/api/v1/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('user_notifications', ['id' => $notification->id]);
    }

    public function test_complaint_assignment_notifies_employee_and_citizen(): void
    {
        $admin = $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        $citizen = User::factory()->citizen()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $complaint = $this->complaint($department, $category, $priority, [
            'citizen_id' => $citizen->id,
            'status' => 'under_review',
        ]);

        $this->patchJson("/api/v1/admin/complaints/{$complaint->id}/assign", [
            'assigned_employee_id' => $employee->id,
            'note' => 'Assign for testing.',
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $employee->id,
            'complaint_id' => $complaint->id,
            'type' => NotificationService::TYPE_COMPLAINT_ASSIGNED,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $citizen->id,
            'complaint_id' => $complaint->id,
            'type' => NotificationService::TYPE_COMPLAINT_ASSIGNED,
        ]);
        Notification::assertSentTo([$employee, $citizen], ComplaintEventNotification::class);
        $this->assertSame('admin', $admin->role);
    }

    public function test_status_update_notifies_citizen(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        $citizen = User::factory()->citizen()->create();
        $complaint = $this->complaint($department, $category, $priority, [
            'citizen_id' => $citizen->id,
            'status' => 'submitted',
        ]);

        $this->patchJson("/api/v1/admin/complaints/{$complaint->id}/status", [
            'status' => 'under_review',
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $citizen->id,
            'complaint_id' => $complaint->id,
            'type' => NotificationService::TYPE_COMPLAINT_STATUS_UPDATED,
        ]);
    }

    public function test_sla_breach_notifies_admins_and_assigned_employee(): void
    {
        [$department, $category, $priority] = $this->lookups();
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $complaint = $this->complaint($department, $category, $priority, [
            'assigned_employee_id' => $employee->id,
            'status' => 'assigned',
            'due_at' => now()->subHour(),
        ]);

        $this->artisan('complaints:check-sla')->assertExitCode(0);

        foreach ([$admin, $employee] as $user) {
            $this->assertDatabaseHas('user_notifications', [
                'user_id' => $user->id,
                'complaint_id' => $complaint->id,
                'type' => NotificationService::TYPE_SLA_BREACHED,
            ]);
        }
        Notification::assertSentTo([$admin, $employee], ComplaintEventNotification::class);
    }

    public function test_unauthenticated_user_cannot_access_notifications_api(): void
    {
        $notification = UserNotification::factory()->create();

        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
        $this->patchJson("/api/v1/notifications/{$notification->id}/read")->assertUnauthorized();
        $this->patchJson('/api/v1/notifications/read-all')->assertUnauthorized();
        $this->deleteJson("/api/v1/notifications/{$notification->id}")->assertUnauthorized();
    }

    private function actingAsCitizen(): User
    {
        $user = User::factory()->citizen()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array{0: Department, 1: ComplaintCategory, 2: Priority}
     */
    private function lookups(): array
    {
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $priority = Priority::factory()->create();

        return [$department, $category, $priority];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function complaint(Department $department, ComplaintCategory $category, Priority $priority, array $overrides = []): Complaint
    {
        return Complaint::factory()->create(array_merge([
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
        ], $overrides));
    }
}

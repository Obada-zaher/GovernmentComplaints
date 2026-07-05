<?php

namespace Tests\Feature;

use App\Jobs\Notifications\SendPushNotificationJob;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\NotificationDeliveryLog;
use App\Models\NotificationPreference;
use App\Models\Priority;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Services\Notifications\Channels\PushNotificationService;
use App\Services\Notifications\Channels\SmsNotificationService;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushSmsNotificationInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config([
            'gcms_notifications.push.enabled' => false,
            'gcms_notifications.sms.enabled' => false,
            'gcms_notifications.sms.provider' => 'log',
            'gcms_notifications.sms.twilio.sid' => null,
            'gcms_notifications.sms.twilio.token' => null,
            'gcms_notifications.sms.twilio.from' => null,
        ]);
    }

    public function test_unauthenticated_user_cannot_register_device_token(): void
    {
        $this->postJson('/api/v1/device-tokens', [
            'token' => 'fake-token',
            'platform' => 'android',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_register_device_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/device-tokens', [
            'token' => 'fake-token',
            'platform' => 'android',
            'device_name' => 'Postman Android Device',
            'app_version' => '1.0.0',
        ])->assertCreated()
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.device_name', 'Postman Android Device');

        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $user->id,
            'token' => 'fake-token',
            'is_active' => true,
        ]);
    }

    public function test_registering_same_token_updates_existing_record(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        UserDeviceToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'same-token',
            'platform' => 'web',
            'device_name' => 'Old Device',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/device-tokens', [
            'token' => 'same-token',
            'platform' => 'ios',
            'device_name' => 'iPhone',
        ])->assertCreated()
            ->assertJsonPath('data.platform', 'ios')
            ->assertJsonPath('data.device_name', 'iPhone');

        $this->assertSame(1, UserDeviceToken::query()->where('user_id', $user->id)->where('token', 'same-token')->count());
    }

    public function test_user_can_list_own_tokens(): void
    {
        $user = User::factory()->create();
        $ownToken = UserDeviceToken::factory()->create(['user_id' => $user->id]);
        UserDeviceToken::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/device-tokens')
            ->assertOk()
            ->assertJsonCount(1, 'data.device_tokens')
            ->assertJsonPath('data.device_tokens.0.id', $ownToken->id);
    }

    public function test_user_cannot_delete_another_users_token(): void
    {
        $user = User::factory()->create();
        $otherToken = UserDeviceToken::factory()->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/device-tokens/{$otherToken->id}")
            ->assertForbidden();
    }

    public function test_deleting_token_makes_it_inactive(): void
    {
        $user = User::factory()->create();
        $token = UserDeviceToken::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/v1/device-tokens/{$token->id}")
            ->assertOk();

        $this->assertFalse((bool) UserDeviceToken::withTrashed()->find($token->id)->is_active);
    }

    public function test_user_can_get_default_notification_preferences(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.database_enabled', true)
            ->assertJsonPath('data.email_enabled', true)
            ->assertJsonPath('data.push_enabled', true)
            ->assertJsonPath('data.sms_enabled', false);
    }

    public function test_user_can_update_preferences(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/notification-preferences', [
            'email_enabled' => false,
            'push_enabled' => false,
            'sms_enabled' => true,
            'complaint_assigned' => false,
            'database_enabled' => false,
        ])->assertOk()
            ->assertJsonPath('data.email_enabled', false)
            ->assertJsonPath('data.push_enabled', false)
            ->assertJsonPath('data.sms_enabled', true)
            ->assertJsonPath('data.complaint_assigned', false)
            ->assertJsonPath('data.database_enabled', true);
    }

    public function test_user_cannot_update_another_users_preferences(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        NotificationPreference::factory()->create(['user_id' => $other->id, 'sms_enabled' => false]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/notification-preferences', [
            'sms_enabled' => true,
        ])->assertOk();

        $this->assertFalse((bool) $other->notificationPreference()->first()->sms_enabled);
    }

    public function test_push_is_skipped_when_disabled(): void
    {
        $user = User::factory()->create();
        UserDeviceToken::factory()->create(['user_id' => $user->id]);
        $complaint = Complaint::factory()->create(['citizen_id' => $user->id]);

        app(PushNotificationService::class)->send($user, NotificationService::TYPE_COMPLAINT_ASSIGNED, $complaint, 'Assigned');

        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $user->id,
            'channel' => 'push',
            'type' => NotificationService::TYPE_COMPLAINT_ASSIGNED,
            'status' => 'skipped',
        ]);
    }

    public function test_sms_is_skipped_when_disabled(): void
    {
        $user = User::factory()->create(['phone' => '0991111111']);
        $complaint = Complaint::factory()->create(['citizen_id' => $user->id]);

        app(SmsNotificationService::class)->send($user, NotificationService::TYPE_COMPLAINT_ASSIGNED, $complaint, 'Assigned');

        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $user->id,
            'channel' => 'sms',
            'status' => 'skipped',
        ]);
    }

    public function test_sms_is_skipped_when_user_has_no_phone(): void
    {
        config(['gcms_notifications.sms.enabled' => true]);
        $user = User::factory()->create(['phone' => null]);

        app(SmsNotificationService::class)->send($user, NotificationService::TYPE_COMPLAINT_ASSIGNED, null, 'Assigned');

        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $user->id,
            'channel' => 'sms',
            'status' => 'skipped',
            'error_message' => 'User has no phone number.',
        ]);
    }

    public function test_log_sms_provider_creates_sent_delivery_log(): void
    {
        config(['gcms_notifications.sms.enabled' => true, 'gcms_notifications.sms.provider' => 'log']);
        $user = User::factory()->create(['phone' => '0991111111']);

        app(SmsNotificationService::class)->send($user, NotificationService::TYPE_COMPLAINT_ASSIGNED, null, 'Assigned');

        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $user->id,
            'channel' => 'sms',
            'status' => 'sent',
            'provider' => 'log',
        ]);
    }

    public function test_provider_failure_creates_failed_delivery_log(): void
    {
        config(['gcms_notifications.sms.enabled' => true, 'gcms_notifications.sms.provider' => 'twilio']);
        $user = User::factory()->create(['phone' => '0991111111']);

        app(SmsNotificationService::class)->send($user, NotificationService::TYPE_COMPLAINT_ASSIGNED, null, 'Assigned');

        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $user->id,
            'channel' => 'sms',
            'status' => 'failed',
            'provider' => 'twilio',
        ]);
    }

    public function test_complaint_assignment_dispatches_database_notification(): void
    {
        [$admin, $employee, $complaint] = $this->assignmentScenario();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/complaints/{$complaint->id}/assign", [
            'assigned_employee_id' => $employee->id,
            'note' => 'Assign for processing.',
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $employee->id,
            'type' => NotificationService::TYPE_COMPLAINT_ASSIGNED,
        ]);
        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $employee->id,
            'channel' => 'database',
            'type' => NotificationService::TYPE_COMPLAINT_ASSIGNED,
            'status' => 'sent',
        ]);
    }

    public function test_complaint_assignment_dispatches_push_job_when_user_has_token(): void
    {
        Queue::fake();
        [$admin, $employee, $complaint] = $this->assignmentScenario();
        UserDeviceToken::factory()->create(['user_id' => $employee->id]);
        config(['gcms_notifications.push.enabled' => true]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/complaints/{$complaint->id}/assign", [
            'assigned_employee_id' => $employee->id,
            'note' => 'Assign for processing.',
        ])->assertOk();

        Queue::assertPushed(SendPushNotificationJob::class);
    }

    public function test_complaint_assignment_does_not_dispatch_sms_when_sms_disabled_by_preferences(): void
    {
        [$admin, $employee, $complaint] = $this->assignmentScenario();
        NotificationPreference::factory()->create(['user_id' => $employee->id, 'sms_enabled' => false]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/complaints/{$complaint->id}/assign", [
            'assigned_employee_id' => $employee->id,
        ])->assertOk();

        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $employee->id,
            'channel' => 'sms',
            'status' => 'skipped',
            'error_message' => 'SMS notifications are disabled by user preferences.',
        ]);
    }

    public function test_sla_breach_creates_delivery_logs_for_configured_channels(): void
    {
        config([
            'gcms_notifications.push.enabled' => true,
            'gcms_notifications.sms.enabled' => true,
            'gcms_notifications.sms.provider' => 'log',
        ]);
        $admin = User::factory()->admin()->create(['phone' => '0991111111']);
        $employee = User::factory()->employee()->create(['phone' => '0992222222']);
        NotificationPreference::factory()->create(['user_id' => $admin->id, 'sms_enabled' => true]);
        NotificationPreference::factory()->create(['user_id' => $employee->id, 'sms_enabled' => true]);
        UserDeviceToken::factory()->create(['user_id' => $admin->id]);
        UserDeviceToken::factory()->create(['user_id' => $employee->id]);
        $complaint = Complaint::factory()->create([
            'assigned_employee_id' => $employee->id,
            'status' => 'in_progress',
            'due_at' => now()->subHour(),
            'is_sla_breached' => false,
        ]);

        $this->artisan('complaints:check-sla')->assertExitCode(0);

        $this->assertDatabaseHas('notification_delivery_logs', [
            'type' => NotificationService::TYPE_SLA_BREACHED,
            'channel' => 'push',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('notification_delivery_logs', [
            'type' => NotificationService::TYPE_SLA_BREACHED,
            'channel' => 'sms',
            'status' => 'sent',
        ]);
        $this->assertTrue((bool) $complaint->fresh()->is_sla_breached);
    }

    public function test_notification_failure_does_not_fail_complaint_status_update(): void
    {
        config(['gcms_notifications.sms.enabled' => true, 'gcms_notifications.sms.provider' => 'twilio']);
        [$admin, $employee, $complaint] = $this->assignmentScenario();
        $complaint->forceFill(['assigned_employee_id' => $employee->id, 'status' => 'assigned'])->save();
        NotificationPreference::factory()->create(['user_id' => $complaint->citizen_id, 'sms_enabled' => true]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/complaints/{$complaint->id}/status", [
            'status' => 'in_progress',
            'note' => 'Start work.',
        ])->assertOk();

        $this->assertSame('in_progress', $complaint->fresh()->status);
        $this->assertDatabaseHas('notification_delivery_logs', [
            'channel' => 'sms',
            'status' => 'failed',
            'provider' => 'twilio',
        ]);
    }

    public function test_admin_can_list_delivery_logs(): void
    {
        $admin = User::factory()->admin()->create();
        NotificationDeliveryLog::factory()->count(2)->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/notification-delivery-logs')
            ->assertOk()
            ->assertJsonCount(2, 'data.delivery_logs');
    }

    public function test_admin_can_show_delivery_log(): void
    {
        $admin = User::factory()->admin()->create();
        $log = NotificationDeliveryLog::factory()->create(['channel' => 'push']);
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/admin/notification-delivery-logs/{$log->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.channel', 'push');
    }

    public function test_citizen_cannot_list_delivery_logs(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());

        $this->getJson('/api/v1/admin/notification-delivery-logs')
            ->assertForbidden();
    }

    public function test_employee_cannot_list_delivery_logs(): void
    {
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->getJson('/api/v1/admin/notification-delivery-logs')
            ->assertForbidden();
    }

    public function test_delivery_log_filters_work_for_channel_and_status(): void
    {
        $admin = User::factory()->admin()->create();
        NotificationDeliveryLog::factory()->create(['channel' => 'push', 'status' => 'sent']);
        NotificationDeliveryLog::factory()->create(['channel' => 'sms', 'status' => 'failed']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/notification-delivery-logs?channel=sms&status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data.delivery_logs')
            ->assertJsonPath('data.delivery_logs.0.channel', 'sms')
            ->assertJsonPath('data.delivery_logs.0.status', 'failed');
    }

    /**
     * @return array{0: User, 1: User, 2: Complaint}
     */
    private function assignmentScenario(): array
    {
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $priority = Priority::factory()->create();
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $citizen = User::factory()->citizen()->create();
        $complaint = Complaint::factory()->create([
            'citizen_id' => $citizen->id,
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
            'status' => 'submitted',
        ]);

        return [$admin, $employee, $complaint];
    }
}

<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Notifications\Complaints\ComplaintEventNotification;
use App\Services\Notifications\NotificationDispatcherService;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class SyncQueueDemoModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'sync',
            'gcms_notifications.push.enabled' => false,
            'gcms_notifications.sms.enabled' => false,
            'gcms_notifications.sms.provider' => 'log',
        ]);
        Notification::fake();
    }

    public function test_sync_queue_executes_email_immediately_and_keeps_in_app_notification(): void
    {
        $user = User::factory()->create();
        $complaint = Complaint::factory()->create(['citizen_id' => $user->id]);

        app(NotificationDispatcherService::class)->dispatch(
            $user,
            NotificationService::TYPE_COMPLAINT_ASSIGNED,
            $complaint,
            'Complaint assigned',
            'Your complaint has been assigned.',
        );

        $this->assertSame('sync', config('queue.default'));
        Notification::assertSentTo($user, ComplaintEventNotification::class);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'complaint_id' => $complaint->id,
            'type' => NotificationService::TYPE_COMPLAINT_ASSIGNED,
        ]);
        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $user->id,
            'channel' => 'email',
            'status' => 'sent',
        ]);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_sync_queue_sends_expo_push_immediately_without_worker(): void
    {
        config(['gcms_notifications.push.enabled' => true]);
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [['status' => 'ok', 'id' => 'sync-expo-ticket']],
            ]),
        ]);
        $user = User::factory()->create();
        $complaint = Complaint::factory()->create(['citizen_id' => $user->id]);
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'email_enabled' => false,
            'push_enabled' => true,
            'sms_enabled' => false,
        ]);
        UserDeviceToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'ExponentPushToken[sync-demo-token]',
        ]);

        app(NotificationDispatcherService::class)->dispatch(
            $user,
            NotificationService::TYPE_COMPLAINT_ASSIGNED,
            $complaint,
            'Complaint assigned',
        );

        Http::assertSentCount(1);
        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $user->id,
            'channel' => 'push',
            'status' => 'sent',
            'provider' => 'expo',
            'provider_message_id' => 'sync-expo-ticket',
        ]);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_sync_expo_failure_does_not_break_complaint_status_update(): void
    {
        config(['gcms_notifications.push.enabled' => true]);
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => Http::response([
                'data' => [[
                    'status' => 'error',
                    'message' => 'Expo delivery failed.',
                    'details' => ['error' => 'MessageTooBig'],
                ]],
            ]),
        ]);
        $admin = User::factory()->admin()->create();
        $citizen = User::factory()->citizen()->create();
        $employee = User::factory()->employee()->create();
        $complaint = Complaint::factory()->create([
            'citizen_id' => $citizen->id,
            'assigned_employee_id' => $employee->id,
            'status' => 'assigned',
        ]);
        UserDeviceToken::factory()->create([
            'user_id' => $citizen->id,
            'token' => 'ExpoPushToken[sync-failure-token]',
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/complaints/{$complaint->id}/status", [
            'status' => 'in_progress',
            'note' => 'Start work.',
        ])->assertOk();

        $this->assertSame('in_progress', $complaint->fresh()->status);
        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $citizen->id,
            'channel' => 'push',
            'status' => 'failed',
            'provider' => 'expo',
        ]);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_sync_queue_isolates_email_provider_failures(): void
    {
        $user = User::factory()->create();
        Notification::swap(new class
        {
            public function send(): void
            {
                throw new RuntimeException('Email provider is unavailable.');
            }
        });

        app(NotificationDispatcherService::class)->dispatch(
            $user,
            NotificationService::TYPE_COMPLAINT_ASSIGNED,
            null,
            'Complaint assigned',
        );

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'type' => NotificationService::TYPE_COMPLAINT_ASSIGNED,
        ]);
        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $user->id,
            'channel' => 'email',
            'status' => 'failed',
            'error_message' => 'Email provider is unavailable.',
        ]);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_sync_queue_executes_log_sms_and_respects_disabled_push_preference(): void
    {
        config([
            'gcms_notifications.push.enabled' => true,
            'gcms_notifications.sms.enabled' => true,
        ]);
        Http::fake();
        $user = User::factory()->create(['phone' => '0991111111']);
        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'email_enabled' => false,
            'push_enabled' => false,
            'sms_enabled' => true,
        ]);
        UserDeviceToken::factory()->create([
            'user_id' => $user->id,
            'token' => 'ExponentPushToken[disabled-sync-token]',
        ]);

        app(NotificationDispatcherService::class)->dispatch(
            $user,
            NotificationService::TYPE_COMPLAINT_ASSIGNED,
            null,
            'Complaint assigned',
        );

        Http::assertNothingSent();
        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $user->id,
            'channel' => 'push',
            'status' => 'skipped',
            'error_message' => 'Push notifications are disabled by user preferences.',
        ]);
        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $user->id,
            'channel' => 'sms',
            'status' => 'sent',
            'provider' => 'log',
        ]);
        $this->assertDatabaseCount('jobs', 0);
    }
}

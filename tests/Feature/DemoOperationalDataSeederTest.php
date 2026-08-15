<?php

namespace Tests\Feature;

use App\Models\ClassificationLog;
use App\Models\Complaint;
use App\Models\NotificationDeliveryLog;
use App\Models\NotificationPreference;
use App\Models\OfflineSubmission;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Reports\ReportService;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoOperationalDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_demo_records_are_complete_safe_and_consistent(): void
    {
        Http::preventStrayRequests();
        Queue::fake();

        $this->seed(DemoDataSeeder::class);

        $primaryCitizen = User::query()->where('email', DemoUsersSeeder::PRIMARY_CITIZEN_EMAIL)->firstOrFail();
        $primaryEmployee = User::query()->where('email', DemoUsersSeeder::PRIMARY_EMPLOYEE_EMAIL)->firstOrFail();
        $admin = User::query()->where('email', DemoUsersSeeder::ADMIN_EMAIL)->firstOrFail();
        $demoComplaintIds = Complaint::query()->where('complaint_number', 'like', 'GCMS-DEMO-%')->pluck('id');

        $this->assertSame(17, User::query()->count());
        $this->assertSame(17, NotificationPreference::query()->whereIn('user_id', User::query()->pluck('id'))->count());
        $this->assertSame(185, UserNotification::query()->whereIn('complaint_id', $demoComplaintIds)->count());
        $this->assertSame(22, UserNotification::query()->where('user_id', $primaryCitizen->id)->whereIn('complaint_id', $demoComplaintIds)->count());
        $this->assertGreaterThan(0, UserNotification::query()->where('user_id', $primaryCitizen->id)->whereNull('read_at')->count());
        $this->assertGreaterThan(0, UserNotification::query()->where('user_id', $primaryEmployee->id)->whereNull('read_at')->count());
        $this->assertGreaterThan(0, UserNotification::query()->where('user_id', $admin->id)->whereNull('read_at')->count());

        $this->assertSame(370, NotificationDeliveryLog::query()->whereIn('complaint_id', $demoComplaintIds)->count());
        $this->assertSame(185, NotificationDeliveryLog::query()->where('channel', 'database')->where('status', 'sent')->count());
        $this->assertSame(185, NotificationDeliveryLog::query()->where('channel', 'push')->where('status', 'skipped')->count());
        $this->assertSame(0, NotificationDeliveryLog::query()->whereNotNull('provider_message_id')->count());
        $this->assertSame(0, NotificationDeliveryLog::query()->where('provider', '!=', 'demo-seed')->count());

        $this->assertSame(12, OfflineSubmission::query()->where('client_uuid', 'like', 'demo-offline-%')->count());
        $this->assertSame(10, OfflineSubmission::query()->where('status', 'synced')->count());
        $this->assertSame(1, OfflineSubmission::query()->where('status', 'pending')->count());
        $this->assertSame(1, OfflineSubmission::query()->where('status', 'failed')->count());
        $this->assertSame(3, OfflineSubmission::query()->where('citizen_id', $primaryCitizen->id)->count());
        $syncedOfflineSubmissions = OfflineSubmission::query()->with('syncedComplaint')->where('status', 'synced')->get();
        $this->assertCount(10, $syncedOfflineSubmissions);
        $this->assertTrue($syncedOfflineSubmissions->every(fn (OfflineSubmission $submission): bool => $submission->syncedComplaint
            && $submission->syncedComplaint->source === 'offline_sync'
            && $submission->syncedComplaint->citizen_id === $submission->citizen_id
            && $submission->syncedComplaint->client_uuid === $submission->client_uuid));

        $classificationLogs = ClassificationLog::query()->with('complaint')->get();
        $this->assertCount(46, $classificationLogs);
        $this->assertTrue($classificationLogs->every(fn (ClassificationLog $log): bool => $log->complaint
            && $log->predicted_department_id === $log->complaint->department_id
            && $log->predicted_category_id === $log->complaint->category_id
            && $log->accepted
            && ! empty($log->scores)
            && ! empty($log->used_rules)));

        $snapshots = ReportSnapshot::query()->get();
        $this->assertCount(7, $snapshots);
        $this->assertSame([
            'complaint_trends',
            'complaints_by_department',
            'complaints_by_priority',
            'complaints_by_status',
            'employee_performance',
            'overview',
            'sla_performance',
        ], $snapshots->pluck('type')->sort()->values()->all());
        $overviewSnapshot = $snapshots->firstWhere('type', 'overview');
        $this->assertSame(50, $overviewSnapshot->data['total_complaints']);
        $this->assertSame('gcms-demo-operational-v1', $overviewSnapshot->filters['demo_seed']);

        $overview = app(ReportService::class)->overview();
        $this->assertSame(50, $overview['total_complaints']);
        $this->assertSame(12, $overview['sla_breached_complaints']);
        $this->assertGreaterThan(0, $overview['new_complaints_today']);
        $this->assertGreaterThan(1, count(app(ReportService::class)->complaintTrends()));

        Queue::assertNothingPushed();
    }

    public function test_operational_seed_is_idempotent_and_preserves_unrelated_operational_data(): void
    {
        $this->seed(DemoDataSeeder::class);

        $unrelatedUser = User::factory()->citizen()->create();
        $unrelatedComplaint = Complaint::factory()->create(['citizen_id' => $unrelatedUser->id]);
        $unrelatedNotification = UserNotification::factory()->create(['user_id' => $unrelatedUser->id, 'complaint_id' => $unrelatedComplaint->id]);
        $unrelatedOffline = OfflineSubmission::factory()->create(['citizen_id' => $unrelatedUser->id, 'client_uuid' => 'manual-offline-001']);
        $unrelatedLog = ClassificationLog::query()->create(['title' => 'Manual classification', 'description' => 'Unrelated record', 'accepted' => false]);
        $unrelatedSnapshot = ReportSnapshot::factory()->create(['filters' => ['status' => 'submitted']]);

        $this->seed(DemoDataSeeder::class);

        $this->assertSame(185, UserNotification::query()->whereHas('complaint', fn ($query) => $query->where('complaint_number', 'like', 'GCMS-DEMO-%'))->count());
        $this->assertSame(370, NotificationDeliveryLog::query()->whereHas('complaint', fn ($query) => $query->where('complaint_number', 'like', 'GCMS-DEMO-%'))->count());
        $this->assertSame(12, OfflineSubmission::query()->where('client_uuid', 'like', 'demo-offline-%')->count());
        $this->assertSame(46, ClassificationLog::query()->whereHas('complaint', fn ($query) => $query->where('complaint_number', 'like', 'GCMS-DEMO-%'))->count());
        $this->assertSame(7, ReportSnapshot::query()->get()->filter(fn (ReportSnapshot $snapshot): bool => ($snapshot->filters['demo_seed'] ?? null) === 'gcms-demo-operational-v1')->count());

        $this->assertDatabaseHas('user_notifications', ['id' => $unrelatedNotification->id]);
        $this->assertDatabaseHas('offline_submissions', ['id' => $unrelatedOffline->id]);
        $this->assertDatabaseHas('classification_logs', ['id' => $unrelatedLog->id]);
        $this->assertDatabaseHas('report_snapshots', ['id' => $unrelatedSnapshot->id]);
    }

    public function test_seeded_data_is_visible_through_existing_citizen_employee_and_admin_apis(): void
    {
        $this->seed(DemoDataSeeder::class);

        $citizen = User::query()->where('email', DemoUsersSeeder::PRIMARY_CITIZEN_EMAIL)->firstOrFail();
        Sanctum::actingAs($citizen);
        $this->getJson('/api/v1/citizen/complaints?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 12)
            ->assertJsonCount(12, 'data.complaints');
        $citizenComplaint = Complaint::query()->where('citizen_id', $citizen->id)->where('status', '!=', 'submitted')->firstOrFail();
        $this->getJson("/api/v1/citizen/complaints/{$citizenComplaint->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $citizenComplaint->id)
            ->assertJsonCount(2, 'data.timeline');
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.total', 22);
        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 8);
        $this->getJson('/api/v1/citizen/offline/submissions')->assertOk()->assertJsonPath('meta.total', 3);

        $employee = User::query()->where('email', DemoUsersSeeder::PRIMARY_EMPLOYEE_EMAIL)->firstOrFail();
        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/employee/complaints?scope=assigned_to_me')->assertOk()->assertJsonPath('meta.total', 4);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.total', 4);
        $employeeComplaint = Complaint::query()->where('assigned_employee_id', $employee->id)->firstOrFail();
        $this->getJson("/api/v1/employee/complaints/{$employeeComplaint->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $employeeComplaint->id)
            ->assertJsonStructure(['data' => ['timeline', 'assignments']]);

        $admin = User::query()->where('email', DemoUsersSeeder::ADMIN_EMAIL)->firstOrFail();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/complaints?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.total', 50)
            ->assertJsonCount(50, 'data.complaints');
        $this->getJson('/api/v1/admin/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.total_complaints', 50)
            ->assertJsonPath('data.open_complaints', 33)
            ->assertJsonPath('data.resolved_complaints', 8)
            ->assertJsonPath('data.sla_breached_complaints', 12);
        $this->getJson('/api/v1/admin/reports/complaints-by-status')->assertOk()->assertJsonCount(9, 'data');
        $this->getJson('/api/v1/admin/reports/complaints-by-department')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/v1/admin/reports/complaints-by-priority')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/v1/admin/reports/sla-performance')->assertOk()->assertJsonPath('data.breached', 12);
        $this->getJson('/api/v1/admin/reports/employee-performance')->assertOk()->assertJsonCount(10, 'data');
        $trends = $this->getJson('/api/v1/admin/reports/complaint-trends')->assertOk();
        $this->assertGreaterThan(1, count($trends->json('data')));
        $this->getJson('/api/v1/admin/notification-delivery-logs?per_page=100')->assertOk()->assertJsonPath('meta.total', 370);
        $this->getJson('/api/v1/admin/reports/snapshots?per_page=100')->assertOk()->assertJsonPath('meta.total', 7);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\ReportSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReportsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_unauthenticated_user_cannot_access_reports(): void
    {
        $this->getJson('/api/v1/admin/reports/overview')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_citizen_cannot_access_reports(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());

        $this->getJson('/api/v1/admin/reports/overview')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_employee_cannot_access_reports(): void
    {
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->getJson('/api/v1/admin/reports/overview')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_access_overview_report(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/reports/overview')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Overview report retrieved successfully.');
    }

    public function test_overview_returns_expected_keys(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/reports/overview')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total_complaints',
                    'open_complaints',
                    'resolved_complaints',
                    'closed_complaints',
                    'rejected_complaints',
                    'escalated_complaints',
                    'sla_breached_complaints',
                    'sla_breach_rate',
                    'average_first_response_minutes',
                    'average_resolution_minutes',
                    'new_complaints_today',
                    'new_complaints_this_week',
                    'new_complaints_this_month',
                ],
                'meta' => ['filters'],
            ]);
    }

    public function test_overview_calculates_total_complaints(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        $this->complaint($department, $category, $priority);
        $this->complaint($department, $category, $priority);

        $this->getJson('/api/v1/admin/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.total_complaints', 2);
    }

    public function test_overview_calculates_status_totals(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();

        foreach (['submitted', 'under_review', 'assigned', 'in_progress', 'waiting_citizen', 'escalated', 'resolved', 'closed', 'rejected'] as $status) {
            $this->complaint($department, $category, $priority, ['status' => $status]);
        }

        $this->getJson('/api/v1/admin/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.open_complaints', 6)
            ->assertJsonPath('data.resolved_complaints', 1)
            ->assertJsonPath('data.closed_complaints', 1)
            ->assertJsonPath('data.rejected_complaints', 1)
            ->assertJsonPath('data.escalated_complaints', 1);
    }

    public function test_overview_calculates_sla_breach_rate_without_division_by_zero(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.total_complaints', 0)
            ->assertJsonPath('data.sla_breach_rate', 0);
    }

    public function test_complaints_by_status_includes_all_statuses(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        $this->complaint($department, $category, $priority, ['status' => 'submitted']);

        $response = $this->getJson('/api/v1/admin/reports/complaints-by-status')
            ->assertOk()
            ->assertJsonCount(9, 'data');

        $this->assertSame([
            'submitted',
            'under_review',
            'assigned',
            'in_progress',
            'waiting_citizen',
            'resolved',
            'closed',
            'rejected',
            'escalated',
        ], collect($response->json('data'))->pluck('status')->all());
    }

    public function test_complaints_by_department_returns_department_metrics(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        $this->complaint($department, $category, $priority, ['status' => 'resolved', 'resolved_at' => now()]);
        $this->complaint($department, $category, $priority, ['status' => 'assigned', 'is_sla_breached' => true]);

        $response = $this->getJson('/api/v1/admin/reports/complaints-by-department')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('department.id', $department->id);
        $this->assertSame(2, $row['total']);
        $this->assertSame(1, $row['open']);
        $this->assertSame(1, $row['resolved']);
        $this->assertSame(1, $row['sla_breached']);
    }

    public function test_complaints_by_priority_returns_priority_metrics(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups(['priority_level' => 3]);
        $this->complaint($department, $category, $priority, ['status' => 'resolved']);
        $this->complaint($department, $category, $priority, ['status' => 'submitted', 'is_sla_breached' => true]);

        $response = $this->getJson('/api/v1/admin/reports/complaints-by-priority')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('priority.id', $priority->id);
        $this->assertSame(2, $row['total']);
        $this->assertSame(1, $row['open']);
        $this->assertSame(1, $row['resolved']);
        $this->assertSame(1, $row['sla_breached']);
    }

    public function test_sla_performance_returns_expected_metrics(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        $this->complaint($department, $category, $priority, ['due_at' => now()->addHour(), 'is_sla_breached' => false]);
        $this->complaint($department, $category, $priority, ['due_at' => now()->subHour(), 'status' => 'assigned', 'is_sla_breached' => false]);
        $this->complaint($department, $category, $priority, ['due_at' => now()->subHours(2), 'is_sla_breached' => true]);
        $this->complaint($department, $category, $priority, ['due_at' => now()->subHour(), 'status' => 'rejected', 'is_sla_breached' => true]);

        $this->getJson('/api/v1/admin/reports/sla-performance')
            ->assertOk()
            ->assertJsonPath('data.total_with_sla', 3)
            ->assertJsonPath('data.within_sla', 1)
            ->assertJsonPath('data.breached', 2)
            ->assertJsonPath('data.breach_rate', 66.67)
            ->assertJsonStructure(['data' => ['by_department', 'by_priority']]);
    }

    public function test_employee_performance_returns_employee_metrics(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $this->complaint($department, $category, $priority, [
            'assigned_employee_id' => $employee->id,
            'status' => 'resolved',
            'first_response_at' => now()->subHours(3),
            'resolved_at' => now(),
        ]);
        $this->complaint($department, $category, $priority, [
            'assigned_employee_id' => $employee->id,
            'status' => 'in_progress',
            'is_sla_breached' => true,
        ]);

        $response = $this->getJson('/api/v1/admin/reports/employee-performance')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('employee.id', $employee->id);
        $this->assertSame(2, $row['assigned_total']);
        $this->assertSame(1, $row['in_progress']);
        $this->assertSame(1, $row['resolved']);
        $this->assertSame(1, $row['sla_breached']);
        $this->assertEquals(50.0, $row['resolution_rate']);
    }

    public function test_complaint_trends_groups_by_day(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        $this->complaint($department, $category, $priority, [
            'created_at' => Carbon::parse('2026-07-01 10:00:00'),
            'updated_at' => Carbon::parse('2026-07-01 10:00:00'),
        ]);
        $this->complaint($department, $category, $priority, [
            'status' => 'resolved',
            'created_at' => Carbon::parse('2026-07-02 10:00:00'),
            'updated_at' => Carbon::parse('2026-07-02 10:00:00'),
            'resolved_at' => Carbon::parse('2026-07-02 16:00:00'),
        ]);

        $response = $this->getJson('/api/v1/admin/reports/complaint-trends?group_by=day')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('period', '2026-07-02');
        $this->assertSame(1, $row['created']);
        $this->assertSame(1, $row['resolved']);
    }

    public function test_complaint_trends_groups_by_month(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        $this->complaint($department, $category, $priority, [
            'created_at' => Carbon::parse('2026-07-01 10:00:00'),
            'updated_at' => Carbon::parse('2026-07-01 10:00:00'),
        ]);
        $this->complaint($department, $category, $priority, [
            'created_at' => Carbon::parse('2026-08-01 10:00:00'),
            'updated_at' => Carbon::parse('2026-08-01 10:00:00'),
        ]);

        $response = $this->getJson('/api/v1/admin/reports/complaint-trends?group_by=month')
            ->assertOk();

        $this->assertSame(['2026-07', '2026-08'], collect($response->json('data'))->pluck('period')->all());
    }

    public function test_sla_breaches_report_returns_paginated_breached_complaints(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $breached = $this->complaint($department, $category, $priority, [
            'assigned_employee_id' => $employee->id,
            'status' => 'assigned',
            'due_at' => now()->subHours(2),
        ]);
        $this->complaint($department, $category, $priority, ['due_at' => now()->addDay()]);

        $this->getJson('/api/v1/admin/reports/sla-breaches?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.complaints.0.id', $breached->id)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure(['data' => ['complaints' => [['delay_minutes']]]]);
    }

    public function test_date_filters_work(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        $this->complaint($department, $category, $priority, ['created_at' => Carbon::parse('2026-06-30 10:00:00')]);
        $this->complaint($department, $category, $priority, ['created_at' => Carbon::parse('2026-07-05 10:00:00')]);

        $this->getJson('/api/v1/admin/reports/overview?date_from=2026-07-01&date_to=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.total_complaints', 1);
    }

    public function test_department_filter_works(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        [$otherDepartment, $otherCategory] = $this->lookups();
        $this->complaint($department, $category, $priority);
        $this->complaint($otherDepartment, $otherCategory, $priority);

        $this->getJson("/api/v1/admin/reports/overview?department_id={$department->id}")
            ->assertOk()
            ->assertJsonPath('data.total_complaints', 1);
    }

    public function test_priority_filter_works(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        $otherPriority = Priority::factory()->create();
        $this->complaint($department, $category, $priority);
        $this->complaint($department, $category, $otherPriority);

        $this->getJson("/api/v1/admin/reports/overview?priority_id={$priority->id}")
            ->assertOk()
            ->assertJsonPath('data.total_complaints', 1);
    }

    public function test_report_endpoints_do_not_mutate_complaints(): void
    {
        $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        $complaint = $this->complaint($department, $category, $priority, [
            'status' => 'assigned',
            'due_at' => now()->subDay(),
            'is_sla_breached' => false,
        ]);

        $this->getJson('/api/v1/admin/reports/sla-performance')->assertOk();
        $this->getJson('/api/v1/admin/reports/sla-breaches')->assertOk();

        $this->assertFalse($complaint->fresh()->is_sla_breached);
    }

    public function test_report_snapshot_can_be_created(): void
    {
        $admin = $this->actingAsAdmin();
        [$department, $category, $priority] = $this->lookups();
        $this->complaint($department, $category, $priority);

        $this->postJson('/api/v1/admin/reports/snapshots', [
            'type' => 'overview',
            'filters' => ['department_id' => $department->id],
        ])->assertCreated()
            ->assertJsonPath('data.type', 'overview')
            ->assertJsonPath('data.generated_by.id', $admin->id)
            ->assertJsonPath('data.data.total_complaints', 1);
    }

    public function test_report_snapshots_can_be_listed(): void
    {
        $admin = $this->actingAsAdmin();
        $snapshot = ReportSnapshot::factory()->create([
            'type' => 'overview',
            'generated_by' => $admin->id,
        ]);
        ReportSnapshot::factory()->create(['type' => 'sla_performance']);

        $this->getJson('/api/v1/admin/reports/snapshots?type=overview')
            ->assertOk()
            ->assertJsonCount(1, 'data.snapshots')
            ->assertJsonPath('data.snapshots.0.id', $snapshot->id);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: Department, 1: ComplaintCategory, 2: Priority}
     */
    private function lookups(array $options = []): array
    {
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $priority = Priority::factory()->create([
            'level' => $options['priority_level'] ?? 1,
        ]);

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
            'created_at' => now(),
            'updated_at' => now(),
            'due_at' => now()->addDay(),
        ], $overrides));
    }
}

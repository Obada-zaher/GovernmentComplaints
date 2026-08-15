<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintAssignment;
use App\Models\ComplaintAttachment;
use App\Models\ComplaintStatusHistory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\User;
use App\Services\Reports\ReportService;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoComplaintsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_complaints_have_the_requested_deterministic_distribution(): void
    {
        $this->seed(DemoDataSeeder::class);

        $demoComplaints = Complaint::query()->where('complaint_number', 'like', 'GCMS-DEMO-%');

        $this->assertSame(50, $demoComplaints->count());
        $this->assertSame(12, (clone $demoComplaints)->whereHas('citizen', fn ($query) => $query->where('email', DemoUsersSeeder::PRIMARY_CITIZEN_EMAIL))->count());
        $this->assertSame(6, (clone $demoComplaints)->distinct('citizen_id')->count('citizen_id'));
        $this->assertSame(0, (clone $demoComplaints)->whereNotBetween('complaint_number', ['GCMS-DEMO-001', 'GCMS-DEMO-050'])->count());

        foreach (['municipality', 'electricity', 'water', 'transportation', 'health'] as $departmentCode) {
            $department = Department::query()->where('code', $departmentCode)->firstOrFail();
            $this->assertSame(10, (clone $demoComplaints)->where('department_id', $department->id)->count());
        }

        foreach ([
            'submitted' => 5,
            'under_review' => 5,
            'assigned' => 6,
            'in_progress' => 9,
            'waiting_citizen' => 4,
            'resolved' => 8,
            'closed' => 6,
            'rejected' => 3,
            'escalated' => 4,
        ] as $status => $count) {
            $this->assertSame($count, (clone $demoComplaints)->where('status', $status)->count());
        }

        foreach (['low' => 10, 'medium' => 18, 'high' => 14, 'urgent' => 8] as $code => $count) {
            $priority = Priority::query()->where('code', $code)->firstOrFail();
            $this->assertSame($count, (clone $demoComplaints)->where('priority_id', $priority->id)->count());
        }

        foreach (['web' => 18, 'mobile' => 18, 'offline_sync' => 10, 'admin' => 4] as $source => $count) {
            $this->assertSame($count, (clone $demoComplaints)->where('source', $source)->count());
        }

        $this->assertSame(10, (clone $demoComplaints)->where('source', 'offline_sync')->whereNotNull('client_uuid')->count());
        $this->assertSame(0, (clone $demoComplaints)->where('source', '!=', 'offline_sync')->whereNotNull('client_uuid')->count());
        $this->assertSame(0, ComplaintAttachment::query()->whereHas('complaint', fn ($query) => $query->where('complaint_number', 'like', 'GCMS-DEMO-%'))->count());
    }

    public function test_demo_complaints_build_valid_timelines_assignments_and_sla_scenarios(): void
    {
        $this->seed(DemoDataSeeder::class);

        $demoComplaints = Complaint::query()
            ->where('complaint_number', 'like', 'GCMS-DEMO-%')
            ->with(['statusHistories' => fn ($query) => $query->oldest(), 'assignments']);

        foreach ($demoComplaints->get() as $complaint) {
            $timeline = $complaint->statusHistories;

            $this->assertNotEmpty($timeline);
            $this->assertNull($timeline->first()->from_status);
            $this->assertSame('submitted', $timeline->first()->to_status);
            $this->assertSame($complaint->status, $timeline->last()->to_status);
            $currentStatusEntry = $timeline
                ->filter(fn (ComplaintStatusHistory $history): bool => $history->to_status === $complaint->status && $history->from_status !== $history->to_status)
                ->last();
            $this->assertNotNull($currentStatusEntry);
            $this->assertSame($currentStatusEntry->created_at?->timestamp, $complaint->status_entered_at?->timestamp);

            foreach ($timeline->values() as $index => $history) {
                if ($index > 0) {
                    $this->assertTrue($history->created_at->greaterThanOrEqualTo($timeline[$index - 1]->created_at));
                    $this->assertGreaterThanOrEqual(0, $history->duration_minutes);
                }
            }

            if ($complaint->status === 'closed') {
                $this->assertTrue($timeline->contains('to_status', 'resolved'));
                $this->assertNotNull($complaint->resolved_at);
                $this->assertNotNull($complaint->closed_at);
                $this->assertTrue($complaint->resolved_at->lessThanOrEqualTo($complaint->closed_at));
            }

            if ($complaint->status === 'rejected') {
                $this->assertSame(['submitted', 'under_review', 'rejected'], $timeline->pluck('to_status')->all());
            }

            if ($complaint->assigned_employee_id) {
                $this->assertNotEmpty($complaint->assignments);
                $this->assertSame($complaint->assigned_employee_id, $complaint->assignments->sortBy('assigned_at')->last()->assigned_to);
                $this->assertSame($complaint->department_id, $complaint->assignedEmployee()->firstOrFail()->department_id);
            } else {
                $this->assertCount(0, $complaint->assignments);
            }
        }

        foreach ([6, 16, 26, 36, 46] as $number) {
            $complaint = Complaint::query()->where('complaint_number', sprintf('GCMS-DEMO-%03d', $number))->with('assignments')->firstOrFail();
            $this->assertCount(2, $complaint->assignments);
            $this->assertSame($complaint->assigned_employee_id, $complaint->assignments->sortBy('assigned_at')->last()->assigned_to);
        }

        $this->assertSame(42, ComplaintAssignment::query()->whereHas('complaint', fn ($query) => $query->where('complaint_number', 'like', 'GCMS-DEMO-%'))->count());
        $this->assertSame(12, Complaint::query()->where('complaint_number', 'like', 'GCMS-DEMO-%')->where('is_sla_breached', true)->count());

        $openBreaches = Complaint::query()
            ->where('complaint_number', 'like', 'GCMS-DEMO-%')
            ->where('is_sla_breached', true)
            ->whereIn('status', ['submitted', 'under_review', 'assigned', 'in_progress', 'waiting_citizen', 'escalated'])
            ->get();
        $this->assertCount(7, $openBreaches);
        $this->assertTrue($openBreaches->every(fn (Complaint $complaint): bool => $complaint->due_at->isPast()));

        $completedBreaches = Complaint::query()
            ->where('complaint_number', 'like', 'GCMS-DEMO-%')
            ->where('is_sla_breached', true)
            ->whereIn('status', ['resolved', 'closed'])
            ->get();
        $this->assertCount(5, $completedBreaches);
        $this->assertTrue($completedBreaches->every(fn (Complaint $complaint): bool => ($complaint->closed_at ?? $complaint->resolved_at)->greaterThan($complaint->due_at)));

        $withinSlaOpenComplaints = Complaint::query()
            ->where('complaint_number', 'like', 'GCMS-DEMO-%')
            ->where('is_sla_breached', false)
            ->whereIn('status', ['submitted', 'under_review', 'assigned', 'in_progress', 'waiting_citizen', 'escalated'])
            ->get();
        $this->assertSame([], $withinSlaOpenComplaints
            ->filter(fn (Complaint $complaint): bool => ! $complaint->due_at->isFuture())
            ->pluck('complaint_number')
            ->all());

        $waitingComplaints = Complaint::query()
            ->where('complaint_number', 'like', 'GCMS-DEMO-%')
            ->where('status', 'waiting_citizen')
            ->with('informationRequests')
            ->get();
        $this->assertTrue($waitingComplaints->every(fn (Complaint $complaint): bool => $complaint->informationRequests
            ->whereIn('status', ['pending', 'responded'])
            ->count() === 1));

        $historicalWaitingComplaints = Complaint::query()
            ->where('complaint_number', 'like', 'GCMS-DEMO-%')
            ->whereIn('status', ['resolved', 'closed'])
            ->with(['informationRequests', 'statusHistories'])
            ->get()
            ->filter(fn (Complaint $complaint): bool => $complaint->statusHistories->contains('to_status', 'waiting_citizen'));
        $this->assertTrue($historicalWaitingComplaints->every(fn (Complaint $complaint): bool => $complaint->informationRequests
            ->where('status', 'completed')
            ->isNotEmpty()));

        $employees = User::query()->where('role', 'employee')->get();
        $this->assertCount(10, $employees);

        foreach ($employees as $employee) {
            $this->assertGreaterThanOrEqual(3, Complaint::query()->where('assigned_employee_id', $employee->id)->count());
        }
    }

    public function test_demo_complaint_seeding_is_idempotent_and_preserves_unrelated_complaints(): void
    {
        $this->seed(DemoDataSeeder::class);

        $unrelatedComplaint = Complaint::factory()->create(['complaint_number' => 'GCMS-MANUAL-001']);
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(50, Complaint::query()->where('complaint_number', 'like', 'GCMS-DEMO-%')->count());
        $this->assertDatabaseHas('complaints', ['id' => $unrelatedComplaint->id, 'complaint_number' => 'GCMS-MANUAL-001']);
        $this->assertSame(51, Complaint::query()->count());
        $this->assertSame(50, ComplaintStatusHistory::query()->whereHas('complaint', fn ($query) => $query->where('complaint_number', 'like', 'GCMS-DEMO-%'))->distinct('complaint_id')->count('complaint_id'));

        $overview = app(ReportService::class)->overview();
        $this->assertGreaterThan(0, $overview['total_complaints']);
        $this->assertGreaterThan(0, $overview['new_complaints_today']);
        $this->assertGreaterThan(0, $overview['new_complaints_this_week']);
        $this->assertGreaterThan(0, $overview['new_complaints_this_month']);
    }
}

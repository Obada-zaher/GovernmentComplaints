<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\ComplaintInformationRequest;
use App\Models\Department;
use App\Models\Priority;
use App\Models\SlaRule;
use App\Models\User;
use App\Services\Complaints\ComplaintStatusService;
use App\Services\Reports\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\AssertsComplaintWorkflowConsistency;
use Tests\TestCase;

class SlaPauseWorkflowTest extends TestCase
{
    use AssertsComplaintWorkflowConsistency, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_waiting_citizen_pauses_sla_and_resume_extends_the_existing_deadline_exactly_once(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00');
        [$employee, $citizen, $complaint] = $this->inProgressComplaint(now()->addHours(8));
        $originalDueAt = $complaint->due_at;
        $this->requestInformation($employee, $complaint);
        $this->assertSame(now()->timestamp, $complaint->fresh()->sla_paused_at?->timestamp);

        Carbon::setTestNow('2026-08-16 12:30:15');
        $this->respond($citizen, $complaint);
        $this->assertSame($originalDueAt->timestamp, $complaint->fresh()->due_at->timestamp);
        $this->resume($employee, $complaint);

        $complaint = $complaint->fresh();
        $this->assertSame($originalDueAt->copy()->addSeconds(9015)->timestamp, $complaint->due_at->timestamp);
        $this->assertSame(9015, $complaint->sla_total_paused_seconds);
        $this->assertNull($complaint->sla_paused_at);
        $this->assertComplaintWorkflowIsConsistent($complaint);
    }

    public function test_two_waiting_cycles_accumulate_exact_pause_seconds(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00');
        [$employee, $citizen, $complaint] = $this->inProgressComplaint(now()->addHours(12));
        $dueAt = $complaint->due_at;
        $this->requestInformation($employee, $complaint);
        Carbon::setTestNow('2026-08-16 12:00:00');
        $this->respond($citizen, $complaint);
        $this->resume($employee, $complaint);
        Carbon::setTestNow('2026-08-16 13:00:00');
        $this->requestInformation($employee, $complaint);
        Carbon::setTestNow('2026-08-16 16:00:00');
        $this->respond($citizen, $complaint);
        $this->resume($employee, $complaint);

        $complaint = $complaint->fresh();
        $this->assertSame($dueAt->copy()->addHours(5)->timestamp, $complaint->due_at->timestamp);
        $this->assertSame(18000, $complaint->sla_total_paused_seconds);
    }

    public function test_overdue_complaint_cannot_escape_breach_by_requesting_information(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00');
        [$employee, , $complaint] = $this->inProgressComplaint(now()->subSeconds(5));
        $this->requestInformation($employee, $complaint);

        $complaint = $complaint->fresh();
        $this->assertTrue($complaint->is_sla_breached);
        $this->assertNull($complaint->sla_paused_at);
        $this->assertSame('waiting_citizen', $complaint->status);
    }

    public function test_sla_cron_ignores_a_legitimately_paused_waiting_complaint(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00');
        [$employee, , $complaint] = $this->inProgressComplaint(now()->addHour());
        $this->requestInformation($employee, $complaint);
        $complaint->forceFill(['due_at' => now()->subSecond()])->save();

        $this->artisan('complaints:check-sla')->assertExitCode(0);

        $this->assertFalse($complaint->fresh()->is_sla_breached);
    }

    public function test_priority_recalculation_uses_original_clock_and_rejects_changes_while_paused(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00');
        [$employee, , $complaint] = $this->inProgressComplaint(now()->addHours(10));
        $complaint->forceFill(['created_at' => now()->subHours(3)])->save();
        $newPriority = Priority::factory()->create();
        SlaRule::factory()->create([
            'department_id' => $complaint->department_id,
            'category_id' => $complaint->category_id,
            'priority_id' => $newPriority->id,
            'resolution_time_hours' => 6,
            'is_active' => true,
        ]);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/priority', ['priority_id' => $newPriority->id])
            ->assertOk();
        $this->assertSame(now()->subHours(3)->addHours(6)->timestamp, $complaint->fresh()->due_at->timestamp);

        Sanctum::actingAs($employee);
        $this->requestInformation($employee, $complaint);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/priority', ['priority_id' => $newPriority->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_metadata_notes_do_not_set_first_response_and_resolved_complaints_reject_attachments(): void
    {
        Storage::fake('public');
        [$employee, $citizen, $complaint] = $this->inProgressComplaint(now()->addHour());
        $complaint->forceFill(['first_response_at' => null])->save();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/priority', ['priority_id' => $complaint->priority_id])->assertOk();
        $this->assertNull($complaint->fresh()->first_response_at);

        $complaint->forceFill(['status' => 'resolved'])->save();
        Sanctum::actingAs($citizen);
        $this->post('/api/v1/citizen/complaints/'.$complaint->id.'/attachments', [
            'attachments' => [UploadedFile::fake()->image('late.jpg')->size(100)],
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->getJson('/api/v1/citizen/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonMissingPath('data.sla_paused_at')
            ->assertJsonMissingPath('data.sla_total_paused_seconds')
            ->assertJsonMissingPath('data.information_requests');
    }

    public function test_failed_waiting_upload_cleans_new_file_and_attachment_row(): void
    {
        Storage::fake('public');
        [, $citizen, $complaint] = $this->inProgressComplaint(now()->addHour());
        $complaint->forceFill(['status' => 'waiting_citizen'])->save();
        Sanctum::actingAs($citizen);

        $this->post('/api/v1/citizen/complaints/'.$complaint->id.'/attachments', [
            'attachments' => [UploadedFile::fake()->image('orphan.jpg')->size(100)],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertSame([], Storage::disk('public')->allFiles('complaints/'.$complaint->id));
        $this->assertSame(0, $complaint->attachments()->count());
    }

    public function test_same_status_timeline_events_do_not_reset_the_status_duration_clock(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00');
        [$employee, , $complaint] = $this->inProgressComplaint(now()->addDay());
        $admin = User::factory()->admin()->create();

        Carbon::setTestNow('2026-08-16 11:00:00');
        app(ComplaintStatusService::class)->addTimelineNote($complaint, $admin, 'Priority metadata updated.');
        Carbon::setTestNow('2026-08-16 12:00:00');
        app(ComplaintStatusService::class)->addTimelineNote($complaint, $admin, 'Department metadata reviewed.');
        Carbon::setTestNow('2026-08-16 15:00:00');
        $this->requestInformation($employee, $complaint);

        $history = $complaint->fresh()->statusHistories()->latest('id')->firstOrFail();
        $this->assertSame('in_progress', $history->from_status);
        $this->assertSame('waiting_citizen', $history->to_status);
        $this->assertSame(300, $history->duration_minutes);
    }

    public function test_paused_unbreached_complaints_are_not_current_sla_breaches_in_reports_or_listing(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00');
        [$employee, , $complaint] = $this->inProgressComplaint(now()->addHour());
        $this->requestInformation($employee, $complaint);
        $complaint->forceFill(['due_at' => now()->subSecond()])->save();

        $report = app(ReportService::class)->slaPerformance();

        $this->assertSame(0, $report['breached']);
        $this->assertFalse(app(ReportService::class)->slaBreachesQuery()->whereKey($complaint->id)->exists());
    }

    public function test_sla_deadline_equal_to_now_breaches_unless_the_complaint_is_paused(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00');
        [, , $eligible] = $this->inProgressComplaint(now());
        [, , $paused] = $this->inProgressComplaint(now());
        $paused->forceFill(['sla_paused_at' => now()->subMinute()])->save();

        $this->artisan('complaints:check-sla')->assertExitCode(0);

        $this->assertTrue($eligible->fresh()->is_sla_breached);
        $this->assertFalse($paused->fresh()->is_sla_breached);
    }

    /** @return array{0: User, 1: User, 2: Complaint} */
    private function inProgressComplaint(Carbon $dueAt): array
    {
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $priority = Priority::factory()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);
        $citizen = User::factory()->citizen()->create();
        $complaint = Complaint::factory()->create([
            'citizen_id' => $citizen->id,
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
            'assigned_employee_id' => $employee->id,
            'status' => 'in_progress',
            'due_at' => $dueAt,
        ]);

        return [$employee, $citizen, $complaint];
    }

    private function requestInformation(User $employee, Complaint $complaint): void
    {
        Sanctum::actingAs($employee);
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'waiting_citizen',
            'note' => 'Please provide supporting evidence.',
        ])->assertOk();
    }

    private function respond(User $citizen, Complaint $complaint): void
    {
        Storage::fake('public');
        Sanctum::actingAs($citizen);
        $this->post('/api/v1/citizen/complaints/'.$complaint->id.'/attachments', [
            'attachments' => [UploadedFile::fake()->image('evidence.jpg')->size(100)],
        ], ['Accept' => 'application/json'])->assertOk();
    }

    private function resume(User $employee, Complaint $complaint): void
    {
        Sanctum::actingAs($employee);
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', ['status' => 'in_progress'])->assertOk();
        $this->assertSame('completed', ComplaintInformationRequest::query()->where('complaint_id', $complaint->id)->latest('id')->value('status'));
    }
}

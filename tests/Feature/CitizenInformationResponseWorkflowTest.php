<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\ComplaintInformationRequest;
use App\Models\Department;
use App\Models\Priority;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CitizenInformationResponseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/citizen/complaints/%d/information-response';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_citizen_can_submit_one_text_response_with_timeline_and_current_employee_notification(): void
    {
        [$firstEmployee, $citizen, $complaint] = $this->waitingComplaint();
        $currentEmployee = User::factory()->employee()->create(['department_id' => $firstEmployee->department_id]);
        $complaint->forceFill(['assigned_employee_id' => $currentEmployee->id])->save();
        Sanctum::actingAs($citizen);

        $this->respond($complaint, '  My subscription number is 87451239.  ')
            ->assertOk()
            ->assertJsonPath('message', 'Information response submitted successfully.')
            ->assertJsonPath('data.status', 'waiting_citizen')
            ->assertJsonMissingPath('data.information_requests');

        $request = $this->informationRequest($complaint);
        $this->assertSame('My subscription number is 87451239.', $request->response_message);
        $this->assertSame('responded', $request->status);
        $this->assertNotNull($request->responded_at);
        $this->assertSame('waiting_citizen', $complaint->fresh()->status);
        $this->assertDatabaseHas('complaint_status_histories', [
            'complaint_id' => $complaint->id,
            'changed_by' => $citizen->id,
            'from_status' => 'waiting_citizen',
            'to_status' => 'waiting_citizen',
            'note' => 'My subscription number is 87451239.',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $currentEmployee->id,
            'complaint_id' => $complaint->id,
            'title' => 'Citizen provided additional information',
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $firstEmployee->id,
            'complaint_id' => $complaint->id,
            'title' => 'Citizen provided additional information',
        ]);
    }

    public function test_citizen_cannot_overwrite_a_text_response_or_create_duplicate_events(): void
    {
        [$employee, $citizen, $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);
        $this->respond($complaint, 'The first response is final.')->assertOk();

        $timelineCount = $complaint->statusHistories()
            ->where('note', 'The first response is final.')
            ->count();
        $notificationCount = $this->responseNotificationCount($employee, $complaint);

        $this->respond($complaint, 'This must not overwrite the original response.')
            ->assertUnprocessable()
            ->assertJsonPath('errors.message.0', 'This information request has already received a text response.');

        $this->assertSame('The first response is final.', $this->informationRequest($complaint)->response_message);
        $this->assertSame($timelineCount, $complaint->statusHistories()->where('note', 'The first response is final.')->count());
        $this->assertSame($notificationCount, $this->responseNotificationCount($employee, $complaint));
    }

    public function test_another_citizen_cannot_submit_a_text_response(): void
    {
        [, , $complaint] = $this->waitingComplaint();
        Sanctum::actingAs(User::factory()->citizen()->create());

        $this->respond($complaint, 'I do not own this complaint.')
            ->assertForbidden();

        $this->assertNull($this->informationRequest($complaint)->response_message);
    }

    public function test_text_response_requires_waiting_status_and_an_active_information_request(): void
    {
        [, $citizen, $complaint] = $this->inProgressComplaint();
        Sanctum::actingAs($citizen);

        $this->respond($complaint, 'Not waiting yet.')
            ->assertUnprocessable()
            ->assertJsonPath('errors.complaint.0', 'The complaint is not waiting for additional information.');

        $complaint->forceFill(['status' => 'waiting_citizen'])->save();
        $this->respond($complaint, 'No request exists.')
            ->assertUnprocessable()
            ->assertJsonPath('errors.complaint.0', 'The waiting complaint has no active information request.');

    }

    public function test_text_response_validation_trims_and_rejects_blank_or_oversized_messages(): void
    {
        [, $citizen, $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);

        foreach ([[], ['message' => ''], ['message' => '   '], ['message' => str_repeat('a', 2001)]] as $payload) {
            $this->postJson(sprintf(self::ENDPOINT, $complaint->id), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['message']);
        }

        $this->postJson(sprintf(self::ENDPOINT, $complaint->id), [
            'message' => 'Attachments must use their existing endpoint.',
            'attachments' => ['not accepted here'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments']);

        $this->assertNull($this->informationRequest($complaint)->response_message);
    }

    public function test_citizen_can_submit_text_after_an_attachment_without_resetting_responded_at(): void
    {
        Storage::fake('public');
        Carbon::setTestNow('2026-08-16 10:00:00');
        [, $citizen, $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);
        $this->uploadAttachment($complaint, 'first-proof.jpg');
        $respondedAt = $this->informationRequest($complaint)->responded_at;

        Carbon::setTestNow('2026-08-16 11:00:00');
        $this->respond($complaint, 'The requested number is 87451239.')->assertOk();

        $request = $this->informationRequest($complaint);
        $this->assertSame('responded', $request->status);
        $this->assertSame('The requested number is 87451239.', $request->response_message);
        $this->assertSame($respondedAt?->timestamp, $request->responded_at?->timestamp);
        $this->assertSame('waiting_citizen', $complaint->fresh()->status);
    }

    public function test_citizen_can_upload_multiple_attachments_after_a_text_response(): void
    {
        Storage::fake('public');
        [$employee, $citizen, $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);
        $this->respond($complaint, 'Text response first.')->assertOk();
        $respondedAt = $this->informationRequest($complaint)->responded_at;
        $notificationCount = $this->responseNotificationCount($employee, $complaint);

        $this->uploadAttachment($complaint, 'second-proof.jpg');
        $this->uploadAttachment($complaint, 'third-proof.jpg');

        $request = $this->informationRequest($complaint);
        $this->assertSame('responded', $request->status);
        $this->assertSame($respondedAt?->timestamp, $request->responded_at?->timestamp);
        $this->assertSame(2, $complaint->fresh()->attachments()->count());
        $this->assertSame('waiting_citizen', $complaint->fresh()->status);
        $this->assertSame($notificationCount, $this->responseNotificationCount($employee, $complaint));
    }

    public function test_attachment_only_response_remains_compatible(): void
    {
        Storage::fake('public');
        [, $citizen, $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);

        $this->uploadAttachment($complaint, 'attachment-only.jpg');

        $request = $this->informationRequest($complaint);
        $this->assertSame('responded', $request->status);
        $this->assertNull($request->response_message);
        $this->assertNotNull($request->responded_at);
    }

    public function test_employee_can_resume_or_resolve_after_a_text_response_and_complete_the_request(): void
    {
        [$employee, $citizen, $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);
        $this->respond($complaint, 'Ready for review.')->assertOk();
        Sanctum::actingAs($employee);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $this->assertSame('completed', $this->informationRequest($complaint)->status);

        [$employee, $citizen, $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);
        $this->respond($complaint, 'Ready to resolve.')->assertOk();
        Sanctum::actingAs($employee);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');
        $this->assertSame('completed', $this->informationRequest($complaint)->status);
    }

    public function test_sla_remains_paused_for_text_response_and_resumes_only_when_employee_continues(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00');
        [$employee, $citizen, $complaint] = $this->inProgressComplaint(now()->addHours(8));
        Sanctum::actingAs($employee);
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'waiting_citizen',
            'note' => 'Please provide your subscription number.',
        ])->assertOk();
        $pausedAt = $complaint->fresh()->sla_paused_at;

        Carbon::setTestNow('2026-08-16 11:00:00');
        Sanctum::actingAs($citizen);
        $this->respond($complaint, '87451239')->assertOk();
        $this->assertSame($pausedAt?->timestamp, $complaint->fresh()->sla_paused_at?->timestamp);
        $this->assertSame('waiting_citizen', $complaint->fresh()->status);

        Sanctum::actingAs($employee);
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', ['status' => 'in_progress'])->assertOk();
        $this->assertNull($complaint->fresh()->sla_paused_at);
    }

    /** @return array{0: User, 1: User, 2: Complaint} */
    private function inProgressComplaint(?Carbon $dueAt = null): array
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

    /** @return array{0: User, 1: User, 2: Complaint} */
    private function waitingComplaint(): array
    {
        [$employee, $citizen, $complaint] = $this->inProgressComplaint();
        Sanctum::actingAs($employee);
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'waiting_citizen',
            'note' => 'Please provide the requested information.',
        ])->assertOk();

        return [$employee, $citizen, $complaint];
    }

    private function respond(Complaint $complaint, string $message)
    {
        return $this->postJson(sprintf(self::ENDPOINT, $complaint->id), ['message' => $message]);
    }

    private function uploadAttachment(Complaint $complaint, string $name): void
    {
        $this->post('/api/v1/citizen/complaints/'.$complaint->id.'/attachments', [
            'attachments' => [UploadedFile::fake()->image($name)->size(100)],
        ], ['Accept' => 'application/json'])->assertOk();
    }

    private function informationRequest(Complaint $complaint): ComplaintInformationRequest
    {
        return ComplaintInformationRequest::query()->where('complaint_id', $complaint->id)->latest('id')->firstOrFail();
    }

    private function responseNotificationCount(User $employee, Complaint $complaint): int
    {
        return UserNotification::query()
            ->where('user_id', $employee->id)
            ->where('complaint_id', $complaint->id)
            ->where('title', 'Citizen provided additional information')
            ->count();
    }
}

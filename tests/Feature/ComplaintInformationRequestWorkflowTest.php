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
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComplaintInformationRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_request_additional_information_with_a_note(): void
    {
        [$employee, $citizen, $complaint] = $this->assignedInProgressComplaint();
        Sanctum::actingAs($employee);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'waiting_citizen',
            'note' => 'Please upload a clear copy of the required document.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'waiting_citizen');

        $this->assertDatabaseHas('complaint_information_requests', [
            'complaint_id' => $complaint->id,
            'requested_by' => $employee->id,
            'status' => 'pending',
            'message' => 'Please upload a clear copy of the required document.',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $citizen->id,
            'complaint_id' => $complaint->id,
            'type' => 'complaint_status_updated',
            'title' => 'Additional information is required',
        ]);
    }

    public function test_waiting_citizen_requires_a_non_blank_note(): void
    {
        [$employee, , $complaint] = $this->assignedInProgressComplaint();
        Sanctum::actingAs($employee);

        foreach ([null, '', '   '] as $note) {
            $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
                'status' => 'waiting_citizen',
                'note' => $note,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(['note']);
        }

        $this->assertDatabaseHas('complaints', ['id' => $complaint->id, 'status' => 'in_progress']);
        $this->assertSame(0, ComplaintInformationRequest::query()->count());
    }

    public function test_admin_generic_status_endpoint_creates_the_information_request_atomically(): void
    {
        [$employee, , $complaint] = $this->assignedInProgressComplaint();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/status', [
            'status' => 'waiting_citizen',
            'note' => 'Please provide the missing proof.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'waiting_citizen');

        $this->assertDatabaseHas('complaint_information_requests', [
            'complaint_id' => $complaint->id,
            'requested_by' => $admin->id,
            'status' => 'pending',
        ]);
        $this->assertSame($employee->id, $complaint->fresh()->assigned_employee_id);
    }

    public function test_duplicate_active_information_request_is_rejected(): void
    {
        [$employee, , $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($employee);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'waiting_citizen',
            'note' => 'A second request must not replace the first.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(1, ComplaintInformationRequest::query()->where('complaint_id', $complaint->id)->count());
    }

    public function test_citizen_upload_marks_pending_request_responded_without_resuming_complaint(): void
    {
        Storage::fake('public');
        [, $citizen, $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);

        $this->post('/api/v1/citizen/complaints/'.$complaint->id.'/attachments', [
            'attachments' => [UploadedFile::fake()->image('requested-proof.jpg')->size(100)],
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting_citizen')
            ->assertJsonCount(1, 'data.attachments');

        $request = ComplaintInformationRequest::query()->where('complaint_id', $complaint->id)->firstOrFail();
        $this->assertSame('responded', $request->status);
        $this->assertNotNull($request->responded_at);
        $this->assertSame('waiting_citizen', $complaint->fresh()->status);
    }

    public function test_second_waiting_citizen_upload_keeps_original_response_and_does_not_duplicate_notification(): void
    {
        Storage::fake('public');
        [$employee, $citizen, $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);

        $this->uploadAttachment($complaint, 'first-proof.jpg');
        $request = ComplaintInformationRequest::query()->where('complaint_id', $complaint->id)->firstOrFail();
        $respondedAt = $request->responded_at;
        $notificationCount = UserNotification::query()
            ->where('user_id', $employee->id)
            ->where('complaint_id', $complaint->id)
            ->where('title', 'Citizen provided additional information')
            ->count();

        $this->uploadAttachment($complaint, 'second-proof.jpg');

        $this->assertSame($respondedAt?->timestamp, $request->fresh()->responded_at?->timestamp);
        $this->assertSame($notificationCount, UserNotification::query()
            ->where('user_id', $employee->id)
            ->where('complaint_id', $complaint->id)
            ->where('title', 'Citizen provided additional information')
            ->count());
        $this->assertSame('waiting_citizen', $complaint->fresh()->status);
    }

    public function test_employee_cannot_resume_until_the_citizen_has_responded(): void
    {
        [$employee, , $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($employee);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'in_progress',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame('pending', ComplaintInformationRequest::query()->where('complaint_id', $complaint->id)->value('status'));
    }

    public function test_employee_can_resume_after_response_and_completes_the_request(): void
    {
        Storage::fake('public');
        [$employee, $citizen, $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);
        $this->uploadAttachment($complaint, 'response.jpg');
        Sanctum::actingAs($employee);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'in_progress',
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $request = ComplaintInformationRequest::query()->where('complaint_id', $complaint->id)->firstOrFail();
        $this->assertSame('completed', $request->status);
        $this->assertNotNull($request->completed_at);
    }

    public function test_employee_can_resolve_directly_after_response_and_completes_the_request(): void
    {
        Storage::fake('public');
        [$employee, $citizen, $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);
        $this->uploadAttachment($complaint, 'response.jpg');
        Sanctum::actingAs($employee);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'resolved',
        ])->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $this->assertDatabaseHas('complaint_information_requests', [
            'complaint_id' => $complaint->id,
            'status' => 'completed',
        ]);
    }

    public function test_multiple_information_request_cycles_create_separate_completed_records(): void
    {
        Storage::fake('public');
        [$employee, $citizen, $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);
        $this->uploadAttachment($complaint, 'first-response.jpg');
        Sanctum::actingAs($employee);
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', ['status' => 'in_progress'])->assertOk();
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'waiting_citizen',
            'note' => 'Please provide one more document.',
        ])->assertOk();
        Sanctum::actingAs($citizen);
        $this->uploadAttachment($complaint, 'second-response.jpg');
        Sanctum::actingAs($employee);
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', ['status' => 'in_progress'])->assertOk();

        $this->assertSame(2, ComplaintInformationRequest::query()->where('complaint_id', $complaint->id)->count());
        $this->assertSame(2, ComplaintInformationRequest::query()->where('complaint_id', $complaint->id)->where('status', 'completed')->count());
    }

    public function test_citizen_cannot_respond_to_another_citizens_waiting_complaint(): void
    {
        Storage::fake('public');
        [, , $complaint] = $this->waitingComplaint();
        Sanctum::actingAs(User::factory()->citizen()->create());

        $this->post('/api/v1/citizen/complaints/'.$complaint->id.'/attachments', [
            'attachments' => [UploadedFile::fake()->image('proof.jpg')->size(100)],
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame('pending', ComplaintInformationRequest::query()->where('complaint_id', $complaint->id)->value('status'));
    }

    public function test_coworker_cannot_process_another_employees_waiting_complaint(): void
    {
        [$employee, , $complaint] = $this->waitingComplaint();
        $coworker = User::factory()->employee()->create(['department_id' => $employee->department_id]);
        Sanctum::actingAs($coworker);

        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'in_progress',
        ])->assertForbidden();
    }

    public function test_citizen_response_notifies_the_current_assigned_employee_after_reassignment(): void
    {
        Storage::fake('public');
        [$firstEmployee, $citizen, $complaint] = $this->waitingComplaint();
        $secondEmployee = User::factory()->employee()->create(['department_id' => $firstEmployee->department_id]);
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/admin/complaints/'.$complaint->id.'/assign', [
            'assigned_employee_id' => $secondEmployee->id,
        ])->assertOk();
        Sanctum::actingAs($citizen);

        $this->uploadAttachment($complaint, 'response.jpg');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $secondEmployee->id,
            'complaint_id' => $complaint->id,
            'title' => 'Citizen provided additional information',
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $firstEmployee->id,
            'complaint_id' => $complaint->id,
            'title' => 'Citizen provided additional information',
        ]);
    }

    public function test_normal_attachment_upload_and_complaint_resource_remain_compatible_outside_waiting_citizen(): void
    {
        Storage::fake('public');
        [, $citizen, $complaint] = $this->assignedInProgressComplaint();
        Sanctum::actingAs($citizen);

        $this->uploadAttachment($complaint, 'normal-upload.jpg');
        $this->assertSame(0, ComplaintInformationRequest::query()->where('complaint_id', $complaint->id)->count());
        $this->getJson('/api/v1/citizen/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonMissingPath('data.information_requests')
            ->assertJsonCount(1, 'data.attachments');

        $employee = $complaint->assignedEmployee;
        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/employee/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonMissingPath('data.information_requests');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonMissingPath('data.information_requests');
    }

    /** @return array{0: User, 1: User, 2: Complaint} */
    private function assignedInProgressComplaint(): array
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
        ]);

        return [$employee, $citizen, $complaint];
    }

    /** @return array{0: User, 1: User, 2: Complaint} */
    private function waitingComplaint(): array
    {
        [$employee, $citizen, $complaint] = $this->assignedInProgressComplaint();
        Sanctum::actingAs($employee);
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'waiting_citizen',
            'note' => 'Please provide the requested document.',
        ])->assertOk();

        return [$employee, $citizen, $complaint];
    }

    private function uploadAttachment(Complaint $complaint, string $name): void
    {
        $this->post('/api/v1/citizen/complaints/'.$complaint->id.'/attachments', [
            'attachments' => [UploadedFile::fake()->image($name)->size(100)],
        ], ['Accept' => 'application/json'])->assertOk();
    }
}

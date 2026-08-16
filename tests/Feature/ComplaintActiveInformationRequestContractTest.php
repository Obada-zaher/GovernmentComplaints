<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComplaintActiveInformationRequestContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_endpoints_return_a_safe_active_pending_information_request(): void
    {
        [$employee, $citizen, $complaint] = $this->waitingComplaint();
        $requestId = $complaint->informationRequests()->value('id');
        $admin = User::factory()->admin()->create();

        foreach ([
            [$citizen, '/api/v1/citizen/complaints/'.$complaint->id],
            [$employee, '/api/v1/employee/complaints/'.$complaint->id],
            [$admin, '/api/v1/admin/complaints/'.$complaint->id],
        ] as [$user, $endpoint]) {
            Sanctum::actingAs($user);

            $this->getJson($endpoint)
                ->assertOk()
                ->assertJsonPath('data.active_information_request.id', $requestId)
                ->assertJsonPath('data.active_information_request.message', 'Please provide the requested document.')
                ->assertJsonPath('data.active_information_request.status', 'pending')
                ->assertJsonPath('data.active_information_request.response_message', null)
                ->assertJsonPath('data.active_information_request.requested_by.id', $employee->id)
                ->assertJsonPath('data.active_information_request.requested_by.name', $employee->name)
                ->assertJsonStructure([
                    'data' => [
                        'active_information_request' => [
                            'id',
                            'message',
                            'status',
                            'requested_at',
                            'responded_at',
                            'response_message',
                            'requested_by' => ['id', 'name'],
                        ],
                    ],
                ])
                ->assertJsonMissingPath('data.active_information_request.requested_by.email')
                ->assertJsonMissingPath('data.information_requests');
        }
    }

    public function test_detail_returns_attachment_only_and_text_responses_through_the_safe_read_model(): void
    {
        Storage::fake('public');
        [, $citizen, $attachmentComplaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);

        $this->post('/api/v1/citizen/complaints/'.$attachmentComplaint->id.'/attachments', [
            'attachments' => [UploadedFile::fake()->image('requested-proof.jpg')->size(100)],
        ], ['Accept' => 'application/json'])->assertOk();

        $attachmentDetail = $this->getJson('/api/v1/citizen/complaints/'.$attachmentComplaint->id)
            ->assertOk()
            ->assertJsonPath('data.active_information_request.status', 'responded')
            ->assertJsonPath('data.active_information_request.response_message', null)
            ->assertJsonStructure(['data' => ['active_information_request' => ['responded_at']]])
            ->assertJsonMissingPath('data.information_requests');
        $this->assertNotNull($attachmentDetail->json('data.active_information_request.responded_at'));

        [, $citizen, $textComplaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);
        $this->postJson('/api/v1/citizen/complaints/'.$textComplaint->id.'/information-response', [
            'message' => 'My subscription number is 87451239.',
        ])->assertOk();

        $textDetail = $this->getJson('/api/v1/citizen/complaints/'.$textComplaint->id)
            ->assertOk()
            ->assertJsonPath('data.active_information_request.status', 'responded')
            ->assertJsonPath('data.active_information_request.response_message', 'My subscription number is 87451239.')
            ->assertJsonStructure(['data' => ['active_information_request' => ['responded_at']]])
            ->assertJsonMissingPath('data.information_requests');
        $this->assertNotNull($textDetail->json('data.active_information_request.responded_at'));
    }

    public function test_detail_returns_null_after_the_active_information_request_is_completed(): void
    {
        [$employee, $citizen, $complaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);
        $this->postJson('/api/v1/citizen/complaints/'.$complaint->id.'/information-response', [
            'message' => 'Ready for review.',
        ])->assertOk();

        Sanctum::actingAs($employee);
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', ['status' => 'in_progress'])
            ->assertOk();

        Sanctum::actingAs($citizen);
        $this->getJson('/api/v1/citizen/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonPath('data.active_information_request', null)
            ->assertJsonMissingPath('data.information_requests');

        [$employee, $citizen, $resolvedComplaint] = $this->waitingComplaint();
        Sanctum::actingAs($citizen);
        $this->postJson('/api/v1/citizen/complaints/'.$resolvedComplaint->id.'/information-response', [
            'message' => 'Ready to resolve.',
        ])->assertOk();
        Sanctum::actingAs($employee);
        $this->patchJson('/api/v1/employee/complaints/'.$resolvedComplaint->id.'/status', ['status' => 'resolved'])
            ->assertOk();

        Sanctum::actingAs($citizen);
        $this->getJson('/api/v1/citizen/complaints/'.$resolvedComplaint->id)
            ->assertOk()
            ->assertJsonPath('data.active_information_request', null);
    }

    public function test_detail_selects_the_newest_active_information_request_across_multiple_cycles(): void
    {
        [$employee, $citizen, $complaint] = $this->waitingComplaint();
        $firstRequestId = $complaint->informationRequests()->value('id');
        Sanctum::actingAs($citizen);
        $this->postJson('/api/v1/citizen/complaints/'.$complaint->id.'/information-response', [
            'message' => 'First response.',
        ])->assertOk();
        Sanctum::actingAs($employee);
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', ['status' => 'in_progress'])
            ->assertOk();
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'waiting_citizen',
            'note' => 'Please provide one more document.',
        ])->assertOk();

        $secondRequestId = $complaint->informationRequests()->latest('id')->value('id');
        $this->assertNotSame($firstRequestId, $secondRequestId);
        Sanctum::actingAs($citizen);

        $this->getJson('/api/v1/citizen/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonPath('data.active_information_request.id', $secondRequestId)
            ->assertJsonPath('data.active_information_request.status', 'pending')
            ->assertJsonPath('data.active_information_request.message', 'Please provide one more document.');

        $this->postJson('/api/v1/citizen/complaints/'.$complaint->id.'/information-response', [
            'message' => 'Second response.',
        ])->assertOk();

        $this->getJson('/api/v1/citizen/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonPath('data.active_information_request.id', $secondRequestId)
            ->assertJsonPath('data.active_information_request.status', 'responded')
            ->assertJsonPath('data.active_information_request.response_message', 'Second response.');
    }

    public function test_detail_without_a_request_is_null_while_lists_remain_light_and_citizen_ownership_is_enforced(): void
    {
        [$employee, $citizen, $complaint] = $this->inProgressComplaint();
        Sanctum::actingAs($citizen);

        $this->getJson('/api/v1/citizen/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonPath('data.active_information_request', null)
            ->assertJsonMissingPath('data.information_requests');
        $this->getJson('/api/v1/citizen/complaints')
            ->assertOk()
            ->assertJsonMissingPath('data.complaints.0.active_information_request')
            ->assertJsonMissingPath('data.complaints.0.information_requests');

        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/employee/complaints')
            ->assertOk()
            ->assertJsonMissingPath('data.complaints.0.active_information_request')
            ->assertJsonMissingPath('data.complaints.0.information_requests');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/complaints')
            ->assertOk()
            ->assertJsonMissingPath('data.complaints.0.active_information_request')
            ->assertJsonMissingPath('data.complaints.0.information_requests');

        Sanctum::actingAs(User::factory()->citizen()->create());
        $this->getJson('/api/v1/citizen/complaints/'.$complaint->id)
            ->assertForbidden();
    }

    /** @return array{0: User, 1: User, 2: Complaint} */
    private function inProgressComplaint(): array
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
        [$employee, $citizen, $complaint] = $this->inProgressComplaint();
        Sanctum::actingAs($employee);
        $this->patchJson('/api/v1/employee/complaints/'.$complaint->id.'/status', [
            'status' => 'waiting_citizen',
            'note' => 'Please provide the requested document.',
        ])->assertOk();

        return [$employee, $citizen, $complaint];
    }
}

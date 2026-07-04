<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintAttachment;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\SlaRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CitizenComplaintApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_citizen_can_create_complaint_with_title_and_description(): void
    {
        $citizen = $this->actingAsCitizen();

        $response = $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Street light is broken',
            'description' => 'The street light near my house has been broken for three days.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Street light is broken')
            ->assertJsonPath('data.status', 'submitted');

        $this->assertDatabaseHas('complaints', [
            'citizen_id' => $citizen->id,
            'title' => 'Street light is broken',
            'status' => 'submitted',
            'source' => 'web',
        ]);
    }

    public function test_citizen_can_create_complaint_with_department_category_and_priority(): void
    {
        $this->actingAsCitizen();
        [$department, $category, $priority] = $this->departmentCategoryPriority();

        $response = $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Road damage',
            'description' => 'There is a pothole in the main road.',
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'address' => 'Damascus',
            'source' => 'mobile',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.department.id', $department->id)
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.priority.id', $priority->id)
            ->assertJsonPath('data.source', 'mobile');
    }

    public function test_category_department_mismatch_returns_validation_error(): void
    {
        $this->actingAsCitizen();
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $otherDepartment->id]);

        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Mismatch',
            'description' => 'Category belongs to another department.',
            'department_id' => $department->id,
            'category_id' => $category->id,
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_category_without_department_infers_department(): void
    {
        $this->actingAsCitizen();
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);

        $response = $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Water issue',
            'description' => 'Water has been leaking from the street line.',
            'category_id' => $category->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.department.id', $department->id);

        $this->assertDatabaseHas('complaints', [
            'id' => $response->json('data.id'),
            'department_id' => $department->id,
            'category_id' => $category->id,
        ]);
    }

    public function test_default_priority_is_medium_when_priority_id_missing(): void
    {
        $this->actingAsCitizen();
        $medium = Priority::factory()->create(['name' => 'Medium', 'code' => 'medium']);

        $response = $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Default priority',
            'description' => 'Priority should default to medium.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.priority.id', $medium->id);
    }

    public function test_complaint_number_is_generated_in_yearly_sequence_format(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00'));
        $this->actingAsCitizen();

        $response = $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Number format',
            'description' => 'Complaint number should be generated.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.complaint_number', 'GCMS-2026-000001');
    }

    public function test_complaint_status_is_submitted_by_default(): void
    {
        $this->actingAsCitizen();

        $response = $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Submitted default',
            'description' => 'Complaint status should default to submitted.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'submitted');
    }

    public function test_first_timeline_record_is_created(): void
    {
        $citizen = $this->actingAsCitizen();

        $response = $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Timeline',
            'description' => 'First timeline record should be created.',
        ]);

        $this->assertDatabaseHas('complaint_status_histories', [
            'complaint_id' => $response->json('data.id'),
            'changed_by' => $citizen->id,
            'from_status' => null,
            'to_status' => 'submitted',
            'note' => 'Complaint submitted by citizen',
        ]);
    }

    public function test_due_at_is_calculated_when_matching_sla_rule_exists(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00'));
        $this->actingAsCitizen();
        [$department, $category, $priority] = $this->departmentCategoryPriority();

        SlaRule::factory()->create([
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
            'resolution_time_hours' => 36,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'SLA exact match',
            'description' => 'Due date should be calculated from the SLA rule.',
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
        ]);

        $response->assertCreated();
        $this->assertSame(
            now()->addHours(36)->timestamp,
            Carbon::parse($response->json('data.due_at'))->timestamp,
        );
    }

    public function test_due_at_is_null_when_no_sla_rule_exists(): void
    {
        $this->actingAsCitizen();
        $priority = Priority::factory()->create();

        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'No SLA',
            'description' => 'No due date should be set.',
            'priority_id' => $priority->id,
        ])->assertCreated()
            ->assertJsonPath('data.due_at', null);
    }

    public function test_citizen_can_upload_attachment_on_complaint_creation(): void
    {
        Storage::fake('public');
        $this->actingAsCitizen();

        $response = $this->post('/api/v1/citizen/complaints', [
            'title' => 'Attachment',
            'description' => 'The complaint includes an attachment.',
            'attachments' => [
                UploadedFile::fake()->image('proof.jpg')->size(100),
            ],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonCount(1, 'data.attachments');

        $attachment = ComplaintAttachment::query()->firstOrFail();
        Storage::disk('public')->assertExists($attachment->file_path);
    }

    public function test_invalid_attachment_mime_is_rejected(): void
    {
        Storage::fake('public');
        $this->actingAsCitizen();

        $this->post('/api/v1/citizen/complaints', [
            'title' => 'Bad attachment',
            'description' => 'Executable files should be rejected.',
            'attachments' => [
                UploadedFile::fake()->create('bad.exe', 10, 'application/x-msdownload'),
            ],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments.0']);
    }

    public function test_attachment_larger_than_max_size_is_rejected(): void
    {
        Storage::fake('public');
        $this->actingAsCitizen();

        $this->post('/api/v1/citizen/complaints', [
            'title' => 'Large attachment',
            'description' => 'Large files should be rejected.',
            'attachments' => [
                UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf'),
            ],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments.0']);
    }

    public function test_citizen_can_list_only_his_own_complaints(): void
    {
        $citizen = $this->actingAsCitizen();
        $ownComplaint = Complaint::factory()->create(['citizen_id' => $citizen->id, 'title' => 'Mine']);
        Complaint::factory()->create(['title' => 'Not mine']);

        $this->getJson('/api/v1/citizen/complaints')
            ->assertOk()
            ->assertJsonCount(1, 'data.complaints')
            ->assertJsonPath('data.complaints.0.id', $ownComplaint->id);
    }

    public function test_citizen_cannot_view_another_citizen_complaint(): void
    {
        $this->actingAsCitizen();
        $otherComplaint = Complaint::factory()->create();

        $this->getJson('/api/v1/citizen/complaints/'.$otherComplaint->id)
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_unauthenticated_user_cannot_create_complaint(): void
    {
        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Unauthenticated',
            'description' => 'This should be rejected.',
        ])->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_employee_cannot_create_citizen_complaint(): void
    {
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Employee blocked',
            'description' => 'Employees cannot use citizen create route.',
        ])->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_admin_cannot_create_citizen_complaint(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Admin blocked',
            'description' => 'Admins cannot use citizen create route.',
        ])->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_citizen_can_view_complaint_with_attachments_and_timeline(): void
    {
        $citizen = $this->actingAsCitizen();
        $complaint = Complaint::factory()->create(['citizen_id' => $citizen->id]);
        ComplaintAttachment::factory()->create(['complaint_id' => $complaint->id, 'uploaded_by' => $citizen->id]);
        $complaint->statusHistories()->create([
            'changed_by' => $citizen->id,
            'from_status' => null,
            'to_status' => 'submitted',
            'note' => 'Complaint submitted by citizen',
        ]);

        $this->getJson('/api/v1/citizen/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonPath('data.id', $complaint->id)
            ->assertJsonStructure([
                'data' => [
                    'attachments',
                    'timeline',
                    'status_histories',
                ],
            ])
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonCount(1, 'data.timeline');
    }

    public function test_citizen_can_add_attachment_to_own_complaint(): void
    {
        Storage::fake('public');
        $citizen = $this->actingAsCitizen();
        $complaint = Complaint::factory()->create(['citizen_id' => $citizen->id, 'status' => 'submitted']);

        $response = $this->post('/api/v1/citizen/complaints/'.$complaint->id.'/attachments', [
            'attachments' => [
                UploadedFile::fake()->image('extra.png')->size(100),
            ],
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonCount(1, 'data.attachments');

        $this->assertDatabaseHas('complaint_status_histories', [
            'complaint_id' => $complaint->id,
            'from_status' => 'submitted',
            'to_status' => 'submitted',
            'note' => 'Citizen added attachments',
        ]);
    }

    public function test_cannot_add_attachment_to_closed_complaint(): void
    {
        Storage::fake('public');
        $citizen = $this->actingAsCitizen();
        $complaint = Complaint::factory()->create(['citizen_id' => $citizen->id, 'status' => 'closed']);

        $this->post('/api/v1/citizen/complaints/'.$complaint->id.'/attachments', [
            'attachments' => [
                UploadedFile::fake()->image('extra.png')->size(100),
            ],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    private function actingAsCitizen(): User
    {
        $citizen = User::factory()->citizen()->create();
        Sanctum::actingAs($citizen);

        return $citizen;
    }

    /**
     * @return array{0: Department, 1: ComplaintCategory, 2: Priority}
     */
    private function departmentCategoryPriority(): array
    {
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $priority = Priority::factory()->create();

        return [$department, $category, $priority];
    }
}

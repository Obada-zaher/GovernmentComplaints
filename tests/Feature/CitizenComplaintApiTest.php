<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintAttachment;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\SlaRule;
use App\Models\User;
use App\Services\Classification\ComplaintClassificationService;
use App\Services\ComplaintAttachmentService;
use App\Services\ComplaintNumberService;
use App\Services\Complaints\ComplaintInformationRequestService;
use App\Services\ComplaintService;
use App\Services\Notifications\NotificationService;
use App\Services\Sla\SlaDeadlineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
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

    public function test_citizen_can_create_complaint_with_nested_mobile_location_and_client_ref(): void
    {
        $this->actingAsCitizen();

        $response = $this->post('/api/v1/citizen/complaints', [
            'title' => 'Nested mobile location',
            'description' => 'The mobile payload uses multipart-style nested location fields.',
            'client_ref' => 'mobile-online-reference',
            'location' => [
                'lat' => 33.5138,
                'lng' => 36.2765,
                'address' => 'Damascus',
            ],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.latitude', '33.5138000')
            ->assertJsonPath('data.longitude', '36.2765000')
            ->assertJsonPath('data.address', 'Damascus')
            ->assertJsonPath('data.location.lat', '33.5138000')
            ->assertJsonPath('data.location.lng', '36.2765000')
            ->assertJsonPath('data.client_uuid', 'mobile-online-reference')
            ->assertJsonPath('data.client_ref', 'mobile-online-reference');
    }

    public function test_flat_complaint_fields_take_precedence_over_nested_mobile_fields(): void
    {
        $this->actingAsCitizen();

        $response = $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Flat values win',
            'description' => 'Existing client fields remain authoritative.',
            'client_uuid' => 'canonical-client-uuid',
            'client_ref' => 'nested-client-reference',
            'latitude' => 30.1,
            'longitude' => 31.2,
            'address' => 'Flat address',
            'location' => [
                'lat' => 33.5138,
                'lng' => 36.2765,
                'address' => 'Nested address',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.latitude', '30.1000000')
            ->assertJsonPath('data.longitude', '31.2000000')
            ->assertJsonPath('data.address', 'Flat address')
            ->assertJsonPath('data.client_uuid', 'canonical-client-uuid')
            ->assertJsonPath('data.client_ref', 'canonical-client-uuid');
    }

    public function test_nested_mobile_location_uses_existing_coordinate_validation(): void
    {
        $this->actingAsCitizen();

        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Invalid coordinates',
            'description' => 'Nested coordinates must use the same validation rules.',
            'location' => [
                'lat' => 91,
                'lng' => -181,
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_complaint_response_contains_additive_mobile_aliases(): void
    {
        $citizen = $this->actingAsCitizen();
        [$department, $category, $priority] = $this->departmentCategoryPriority();
        $employee = User::factory()->employee()->create();
        $dueAt = Carbon::parse('2026-07-06T10:00:00Z');
        $complaint = Complaint::factory()->create([
            'citizen_id' => $citizen->id,
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
            'assigned_employee_id' => $employee->id,
            'client_uuid' => 'response-client-uuid',
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'address' => 'Damascus',
            'due_at' => $dueAt,
        ]);

        $this->getJson('/api/v1/citizen/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonPath('data.client_uuid', 'response-client-uuid')
            ->assertJsonPath('data.client_ref', 'response-client-uuid')
            ->assertJsonPath('data.department_id', $department->id)
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.priority_id', $priority->id)
            ->assertJsonPath('data.location.lat', '33.5138000')
            ->assertJsonPath('data.location.lng', '36.2765000')
            ->assertJsonPath('data.location.address', 'Damascus')
            ->assertJsonPath('data.due_at', $dueAt->toISOString())
            ->assertJsonPath('data.sla_due_at', $dueAt->toISOString())
            ->assertJsonPath('data.assigned_employee_id', $employee->id)
            ->assertJsonPath('data.assigned_employee.id', $employee->id);
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

    public function test_inactive_or_deleted_reference_data_cannot_be_used_for_complaint_creation(): void
    {
        $this->actingAsCitizen();
        $department = Department::factory()->create(['is_active' => false]);
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);

        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Inactive reference',
            'description' => 'This must not persist invalid reference data.',
            'department_id' => $department->id,
            'category_id' => $category->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id']);

        $activeDepartment = Department::factory()->create();
        $deletedCategory = ComplaintCategory::factory()->create(['department_id' => $activeDepartment->id]);
        $deletedCategory->delete();

        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Deleted category',
            'description' => 'This must not persist deleted categories.',
            'department_id' => $activeDepartment->id,
            'category_id' => $deletedCategory->id,
        ])->assertUnprocessable()
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
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.assigned_employee_id', null);
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
        config([
            'app.url' => 'https://api.example.test',
            'filesystems.disks.public.url' => 'https://api.example.test/storage',
            'gcms.attachments.disk' => 'public',
        ]);
        $this->actingAsCitizen();

        $response = $this->post('/api/v1/citizen/complaints', [
            'title' => 'Attachment',
            'description' => 'The complaint includes an attachment.',
            'attachments' => [
                UploadedFile::fake()->image('proof.jpg')->size(100),
            ],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.disk', 'public');

        $attachment = ComplaintAttachment::query()->firstOrFail();
        $this->assertDatabaseHas('complaint_attachments', [
            'id' => $attachment->id,
            'complaint_id' => $response->json('data.id'),
            'file_path' => $attachment->file_path,
            'disk' => 'public',
        ]);
        Storage::disk('public')->assertExists($attachment->file_path);

        $attachmentUrl = $response->json('data.attachments.0.url');
        $this->assertNotNull($attachmentUrl);
        $this->assertSame(Storage::disk('public')->url($attachment->file_path), $attachmentUrl);

        $this->getJson('/api/v1/citizen/complaints/'.$response->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.attachments.0.url', $attachmentUrl);
    }

    public function test_failed_create_after_attachment_storage_cleans_only_new_files(): void
    {
        Storage::fake('public');
        $citizen = $this->actingAsCitizen();
        $notifications = \Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('notifyAdmins')->once()->andThrow(new RuntimeException('Notification failure'));

        $service = new ComplaintService(
            app(ComplaintNumberService::class),
            app(ComplaintAttachmentService::class),
            app(SlaDeadlineService::class),
            $notifications,
            app(ComplaintClassificationService::class),
            app(ComplaintInformationRequestService::class),
        );

        try {
            $service->create($citizen, [
                'title' => 'Attachment rollback',
                'description' => 'The attachment must be removed when later work fails.',
                'attachments' => [UploadedFile::fake()->image('proof.jpg')->size(100)],
            ]);
            $this->fail('Complaint creation should fail after attachment storage.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Notification failure', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('public')->allFiles('complaints'));
        $this->assertSame(0, Complaint::query()->count());
        $this->assertSame(0, ComplaintAttachment::query()->count());
    }

    public function test_configured_attachment_disk_is_used_for_new_uploads(): void
    {
        Storage::fake('local');
        config(['gcms.attachments.disk' => 'local']);
        $this->actingAsCitizen();

        $response = $this->post('/api/v1/citizen/complaints', [
            'title' => 'Configured attachment disk',
            'description' => 'The attachment should use the configured disk.',
            'attachments' => [
                UploadedFile::fake()->image('configured.jpg')->size(100),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $attachment = ComplaintAttachment::query()->firstOrFail();

        $this->assertSame('local', $attachment->disk);
        Storage::disk('local')->assertExists($attachment->file_path);
        $this->assertSame($attachment->disk, $response->json('data.attachments.0.disk'));
    }

    public function test_existing_public_attachment_uses_its_stored_disk_for_url_generation(): void
    {
        config([
            'app.url' => 'https://api.example.test',
            'filesystems.disks.public.url' => 'https://api.example.test/storage',
            'gcms.attachments.disk' => 'local',
        ]);
        $citizen = $this->actingAsCitizen();
        $complaint = Complaint::factory()->create(['citizen_id' => $citizen->id]);
        $attachment = ComplaintAttachment::factory()->create([
            'complaint_id' => $complaint->id,
            'uploaded_by' => $citizen->id,
            'file_path' => 'complaints/'.$complaint->id.'/existing.jpg',
            'disk' => 'public',
        ]);

        $this->getJson('/api/v1/citizen/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonPath('data.attachments.0.disk', 'public')
            ->assertJsonPath('data.attachments.0.url', 'https://api.example.test/storage/'.$attachment->file_path);
    }

    public function test_s3_attachment_uses_its_stored_disk_for_url_generation(): void
    {
        Storage::fake('s3');
        config(['gcms.attachments.disk' => 'public']);
        $citizen = $this->actingAsCitizen();
        $complaint = Complaint::factory()->create(['citizen_id' => $citizen->id]);
        $attachment = ComplaintAttachment::factory()->create([
            'complaint_id' => $complaint->id,
            'uploaded_by' => $citizen->id,
            'file_path' => 'complaints/'.$complaint->id.'/persistent.jpg',
            'disk' => 's3',
        ]);

        $this->getJson('/api/v1/citizen/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonPath('data.attachments.0.disk', 's3')
            ->assertJsonPath('data.attachments.0.url', Storage::disk('s3')->url($attachment->file_path));
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

    public function test_missing_sort_defaults_to_newest_first(): void
    {
        $citizen = $this->actingAsCitizen();
        $older = $this->complaintForCitizen($citizen, ['created_at' => '2026-07-01 10:00:00']);
        $newer = $this->complaintForCitizen($citizen, ['created_at' => '2026-07-02 10:00:00']);

        $this->getJson('/api/v1/citizen/complaints?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.complaints.0.id', $newer->id)
            ->assertJsonPath('data.complaints.1.id', $older->id);
    }

    public function test_newest_sort_orders_by_created_at_then_id_descending(): void
    {
        $citizen = $this->actingAsCitizen();
        $first = $this->complaintForCitizen($citizen, ['created_at' => '2026-07-01 10:00:00']);
        $second = $this->complaintForCitizen($citizen, ['created_at' => '2026-07-01 10:00:00']);
        $newest = $this->complaintForCitizen($citizen, ['created_at' => '2026-07-02 10:00:00']);

        $this->getJson('/api/v1/citizen/complaints?sort=newest&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.complaints.0.id', $newest->id)
            ->assertJsonPath('data.complaints.1.id', $second->id)
            ->assertJsonPath('data.complaints.2.id', $first->id);
    }

    public function test_oldest_sort_orders_by_created_at_then_id_ascending(): void
    {
        $citizen = $this->actingAsCitizen();
        $first = $this->complaintForCitizen($citizen, ['created_at' => '2026-07-01 10:00:00']);
        $second = $this->complaintForCitizen($citizen, ['created_at' => '2026-07-01 10:00:00']);
        $newest = $this->complaintForCitizen($citizen, ['created_at' => '2026-07-02 10:00:00']);

        $this->getJson('/api/v1/citizen/complaints?sort=oldest&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.complaints.0.id', $first->id)
            ->assertJsonPath('data.complaints.1.id', $second->id)
            ->assertJsonPath('data.complaints.2.id', $newest->id);
    }

    public function test_sla_sort_orders_deadlines_first_and_null_deadlines_last(): void
    {
        $citizen = $this->actingAsCitizen();
        $later = $this->complaintForCitizen($citizen, [
            'due_at' => '2026-07-03 10:00:00',
            'created_at' => '2026-07-01 10:00:00',
        ]);
        $earlier = $this->complaintForCitizen($citizen, [
            'due_at' => '2026-07-02 10:00:00',
            'created_at' => '2026-07-02 10:00:00',
        ]);
        $newerNull = $this->complaintForCitizen($citizen, [
            'due_at' => null,
            'created_at' => '2026-07-04 10:00:00',
        ]);
        $olderNull = $this->complaintForCitizen($citizen, [
            'due_at' => null,
            'created_at' => '2026-07-03 10:00:00',
        ]);

        $this->getJson('/api/v1/citizen/complaints?sort=sla&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.complaints.0.id', $earlier->id)
            ->assertJsonPath('data.complaints.1.id', $later->id)
            ->assertJsonPath('data.complaints.2.id', $newerNull->id)
            ->assertJsonPath('data.complaints.3.id', $olderNull->id);
    }

    public function test_sorting_happens_before_pagination(): void
    {
        $citizen = $this->actingAsCitizen();
        $complaints = collect(range(1, 5))->map(fn (int $day) => $this->complaintForCitizen($citizen, [
            'created_at' => "2026-07-0{$day} 10:00:00",
        ]));

        $firstPage = $this->getJson('/api/v1/citizen/complaints?sort=oldest&per_page=2&page=1')
            ->assertOk()
            ->json('data.complaints');
        $secondPage = $this->getJson('/api/v1/citizen/complaints?sort=oldest&per_page=2&page=2')
            ->assertOk()
            ->json('data.complaints');

        $this->assertSame([$complaints[0]->id, $complaints[1]->id], collect($firstPage)->pluck('id')->all());
        $this->assertSame([$complaints[2]->id, $complaints[3]->id], collect($secondPage)->pluck('id')->all());
    }

    public function test_sort_combines_with_status_filter_and_excludes_other_citizens(): void
    {
        $citizen = $this->actingAsCitizen();
        $first = $this->complaintForCitizen($citizen, [
            'status' => 'submitted',
            'created_at' => '2026-07-01 10:00:00',
        ]);
        $second = $this->complaintForCitizen($citizen, [
            'status' => 'submitted',
            'created_at' => '2026-07-02 10:00:00',
        ]);
        $this->complaintForCitizen($citizen, [
            'status' => 'resolved',
            'created_at' => '2026-07-03 10:00:00',
        ]);
        $otherCitizenComplaint = Complaint::factory()->create([
            'status' => 'submitted',
            'created_at' => '2026-07-04 10:00:00',
            'due_at' => '2026-07-01 10:00:00',
        ]);

        $response = $this->getJson('/api/v1/citizen/complaints?status=submitted&sort=oldest&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.complaints.0.id', $first->id)
            ->assertJsonPath('data.complaints.1.id', $second->id)
            ->assertJsonCount(2, 'data.complaints');

        $this->assertNotContains($otherCitizenComplaint->id, collect($response->json('data.complaints'))->pluck('id')->all());
    }

    public function test_unknown_sort_preserves_newest_first_behavior(): void
    {
        $citizen = $this->actingAsCitizen();
        $older = $this->complaintForCitizen($citizen, ['created_at' => '2026-07-01 10:00:00']);
        $newer = $this->complaintForCitizen($citizen, ['created_at' => '2026-07-02 10:00:00']);

        $this->getJson('/api/v1/citizen/complaints?sort=unsupported&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.complaints.0.id', $newer->id)
            ->assertJsonPath('data.complaints.1.id', $older->id);
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
        $citizen = $this->actingAsCitizen()->forceFill(['name' => 'Citizen Detail User']);
        $citizen->save();
        $complaint = Complaint::factory()->create(['citizen_id' => $citizen->id]);
        ComplaintAttachment::factory()->create(['complaint_id' => $complaint->id, 'uploaded_by' => $citizen->id]);
        $complaint->statusHistories()->create([
            'changed_by' => $citizen->id,
            'from_status' => null,
            'to_status' => 'submitted',
            'note' => 'Complaint submitted by citizen',
        ]);

        $response = $this->getJson('/api/v1/citizen/complaints/'.$complaint->id)
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
            ->assertJsonCount(1, 'data.timeline')
            ->assertJsonPath('data.attachments.0.uploaded_by', 'Citizen Detail User')
            ->assertJsonPath('data.timeline.0.changed_by', 'Citizen Detail User');

        $this->assertIsString($response->json('data.attachments.0.uploaded_by'));
        $this->assertIsNotArray($response->json('data.attachments.0.uploaded_by'));
        $this->assertIsString($response->json('data.timeline.0.changed_by'));
        $this->assertIsNotArray($response->json('data.timeline.0.changed_by'));
    }

    public function test_citizen_complaint_detail_returns_null_for_missing_attachment_and_timeline_users(): void
    {
        $citizen = $this->actingAsCitizen();
        $complaint = Complaint::factory()->create(['citizen_id' => $citizen->id]);
        ComplaintAttachment::factory()->create(['complaint_id' => $complaint->id, 'uploaded_by' => null]);
        $complaint->statusHistories()->create([
            'changed_by' => null,
            'from_status' => null,
            'to_status' => 'submitted',
            'note' => 'System submission',
        ]);

        $this->getJson('/api/v1/citizen/complaints/'.$complaint->id)
            ->assertOk()
            ->assertJsonPath('data.attachments.0.uploaded_by', null)
            ->assertJsonPath('data.timeline.0.changed_by', null);
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
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.disk', 'public');

        $attachment = ComplaintAttachment::query()->firstOrFail();
        Storage::disk('public')->assertExists($attachment->file_path);
        $this->assertNotNull($response->json('data.attachments.0.url'));

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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function complaintForCitizen(User $citizen, array $attributes = []): Complaint
    {
        return Complaint::factory()->create(array_merge([
            'citizen_id' => $citizen->id,
        ], $attributes));
    }
}

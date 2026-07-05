<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\OfflineSubmission;
use App\Models\Priority;
use App\Models\SlaRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfflineComplaintSyncApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_unauthenticated_user_cannot_sync_offline_complaint(): void
    {
        $this->postJson('/api/v1/citizen/offline/complaints/sync', $this->payload())
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_employee_cannot_sync_offline_complaint(): void
    {
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->postJson('/api/v1/citizen/offline/complaints/sync', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_admin_cannot_sync_through_citizen_route(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/citizen/offline/complaints/sync', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_citizen_can_sync_offline_complaint(): void
    {
        $this->actingAsCitizen();
        [$department, $category, $priority] = $this->lookups();

        $this->postJson('/api/v1/citizen/offline/complaints/sync', $this->payload($department, $category, $priority))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Offline complaint synced successfully.')
            ->assertJsonPath('data.complaint.title', 'Offline water leakage')
            ->assertJsonPath('data.offline_submission.status', 'synced');
    }

    public function test_synced_complaint_has_source_offline_sync(): void
    {
        $this->actingAsCitizen();
        $response = $this->postJson('/api/v1/citizen/offline/complaints/sync', $this->payload())
            ->assertCreated();

        $this->assertDatabaseHas('complaints', [
            'id' => $response->json('data.complaint.id'),
            'source' => 'offline_sync',
        ]);
    }

    public function test_synced_complaint_has_client_uuid(): void
    {
        $this->actingAsCitizen();
        $clientUuid = 'offline-client-uuid';

        $this->postJson('/api/v1/citizen/offline/complaints/sync', $this->payload(clientUuid: $clientUuid))
            ->assertCreated()
            ->assertJsonPath('data.complaint.client_uuid', $clientUuid);
    }

    public function test_synced_complaint_gets_complaint_number(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00'));
        $this->actingAsCitizen();

        $this->postJson('/api/v1/citizen/offline/complaints/sync', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.complaint.complaint_number', 'GCMS-2026-000001');
    }

    public function test_synced_complaint_creates_first_timeline_record(): void
    {
        $citizen = $this->actingAsCitizen();
        $response = $this->postJson('/api/v1/citizen/offline/complaints/sync', $this->payload())
            ->assertCreated();

        $this->assertDatabaseHas('complaint_status_histories', [
            'complaint_id' => $response->json('data.complaint.id'),
            'changed_by' => $citizen->id,
            'from_status' => null,
            'to_status' => 'submitted',
            'note' => 'Complaint submitted by citizen',
        ]);
    }

    public function test_synced_complaint_calculates_due_at_when_sla_rule_exists(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 10:00:00'));
        $this->actingAsCitizen();
        [$department, $category, $priority] = $this->lookups();
        SlaRule::factory()->create([
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
            'resolution_time_hours' => 12,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/citizen/offline/complaints/sync', $this->payload($department, $category, $priority))
            ->assertCreated();

        $this->assertSame(now()->addHours(12)->timestamp, Carbon::parse($response->json('data.complaint.due_at'))->timestamp);
    }

    public function test_category_without_department_infers_department(): void
    {
        $this->actingAsCitizen();
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);

        $this->postJson('/api/v1/citizen/offline/complaints/sync', $this->payload(category: $category))
            ->assertCreated()
            ->assertJsonPath('data.complaint.department.id', $department->id);
    }

    public function test_category_department_mismatch_returns_validation_error_and_marks_failed(): void
    {
        $this->actingAsCitizen();
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $otherDepartment->id]);

        $this->postJson('/api/v1/citizen/offline/complaints/sync', $this->payload($department, $category))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);

        $this->assertDatabaseHas('offline_submissions', [
            'client_uuid' => 'offline-test-uuid',
            'status' => 'failed',
        ]);
    }

    public function test_offline_submission_record_is_created(): void
    {
        $citizen = $this->actingAsCitizen();

        $this->postJson('/api/v1/citizen/offline/complaints/sync', $this->payload())
            ->assertCreated();

        $this->assertDatabaseHas('offline_submissions', [
            'citizen_id' => $citizen->id,
            'client_uuid' => 'offline-test-uuid',
        ]);
    }

    public function test_offline_submission_status_becomes_synced(): void
    {
        $this->actingAsCitizen();

        $this->postJson('/api/v1/citizen/offline/complaints/sync', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.offline_submission.status', 'synced');

        $this->assertDatabaseHas('offline_submissions', [
            'client_uuid' => 'offline-test-uuid',
            'status' => 'synced',
        ]);
    }

    public function test_duplicate_sync_with_same_citizen_and_client_uuid_does_not_create_duplicate_complaint(): void
    {
        $this->actingAsCitizen();
        $payload = $this->payload();

        $this->postJson('/api/v1/citizen/offline/complaints/sync', $payload)->assertCreated();
        $this->postJson('/api/v1/citizen/offline/complaints/sync', $payload)
            ->assertOk()
            ->assertJsonPath('meta.idempotent', true);

        $this->assertSame(1, Complaint::query()->where('client_uuid', 'offline-test-uuid')->count());
    }

    public function test_duplicate_sync_returns_same_complaint(): void
    {
        $this->actingAsCitizen();
        $payload = $this->payload();

        $first = $this->postJson('/api/v1/citizen/offline/complaints/sync', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/citizen/offline/complaints/sync', $payload)->assertOk();

        $this->assertSame($first->json('data.complaint.id'), $second->json('data.complaint.id'));
        $this->assertSame('Offline complaint was already synced.', $second->json('message'));
    }

    public function test_failed_processing_stores_failed_status(): void
    {
        $this->actingAsCitizen();
        $payload = $this->payload();
        unset($payload['title']);

        $this->postJson('/api/v1/citizen/offline/complaints/sync', $payload)->assertUnprocessable();

        $this->assertDatabaseMissing('offline_submissions', [
            'client_uuid' => 'offline-test-uuid',
        ]);

        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $otherDepartment->id]);

        $this->postJson('/api/v1/citizen/offline/complaints/sync', $this->payload($department, $category, clientUuid: 'failed-processing-uuid'))
            ->assertUnprocessable();

        $this->assertDatabaseHas('offline_submissions', [
            'client_uuid' => 'failed-processing-uuid',
            'status' => 'failed',
        ]);
    }

    public function test_citizen_can_list_own_offline_submissions(): void
    {
        $citizen = $this->actingAsCitizen();
        $own = OfflineSubmission::factory()->create(['citizen_id' => $citizen->id]);
        OfflineSubmission::factory()->create();

        $this->getJson('/api/v1/citizen/offline/submissions')
            ->assertOk()
            ->assertJsonCount(1, 'data.offline_submissions')
            ->assertJsonPath('data.offline_submissions.0.id', $own->id);
    }

    public function test_citizen_cannot_view_another_citizen_offline_submission(): void
    {
        $this->actingAsCitizen();
        $other = OfflineSubmission::factory()->create();

        $this->getJson("/api/v1/citizen/offline/submissions/{$other->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_attachments_can_be_synced(): void
    {
        Storage::fake('public');
        $this->actingAsCitizen();

        $response = $this->post('/api/v1/citizen/offline/complaints/sync', array_merge($this->payload(), [
            'attachments' => [
                UploadedFile::fake()->image('offline-proof.jpg')->size(100),
            ],
        ]), ['Accept' => 'application/json'])->assertCreated();

        $complaint = Complaint::query()->findOrFail($response->json('data.complaint.id'));
        $attachment = $complaint->attachments()->firstOrFail();

        Storage::disk('public')->assertExists($attachment->file_path);
    }

    public function test_invalid_attachment_mime_is_rejected(): void
    {
        Storage::fake('public');
        $this->actingAsCitizen();

        $this->post('/api/v1/citizen/offline/complaints/sync', array_merge($this->payload(), [
            'attachments' => [
                UploadedFile::fake()->create('bad.exe', 10, 'application/x-msdownload'),
            ],
        ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments.0']);
    }

    public function test_offline_submission_list_supports_status_filter(): void
    {
        $citizen = $this->actingAsCitizen();
        $synced = OfflineSubmission::factory()->synced()->create(['citizen_id' => $citizen->id, 'status' => 'synced']);
        OfflineSubmission::factory()->create(['citizen_id' => $citizen->id, 'status' => 'failed']);

        $this->getJson('/api/v1/citizen/offline/submissions?status=synced')
            ->assertOk()
            ->assertJsonCount(1, 'data.offline_submissions')
            ->assertJsonPath('data.offline_submissions.0.id', $synced->id);
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
    private function lookups(array $options = []): array
    {
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $priority = Priority::factory()->create($options);

        return [$department, $category, $priority];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        ?Department $department = null,
        ?ComplaintCategory $category = null,
        ?Priority $priority = null,
        string $clientUuid = 'offline-test-uuid',
    ): array {
        return [
            'client_uuid' => $clientUuid,
            'title' => 'Offline water leakage',
            'description' => 'This complaint was created while offline.',
            'department_id' => $department?->id,
            'category_id' => $category?->id,
            'priority_id' => $priority?->id,
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'address' => 'Damascus',
            'created_offline_at' => '2026-07-05T10:00:00Z',
            'source' => 'offline_sync',
        ];
    }
}

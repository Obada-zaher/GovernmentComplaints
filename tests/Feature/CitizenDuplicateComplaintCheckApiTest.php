<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CitizenDuplicateComplaintCheckApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/citizen/complaints/check-duplicates';

    public function test_guest_cannot_check_for_duplicate_complaints(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload())
            ->assertUnauthorized();
    }

    public function test_only_citizens_can_check_for_duplicate_complaints(): void
    {
        foreach ([User::factory()->employee()->create(), User::factory()->admin()->create()] as $user) {
            Sanctum::actingAs($user);

            $this->postJson(self::ENDPOINT, $this->payload())
                ->assertForbidden();
        }

        $this->actingAsCitizen();

        $this->postJson(self::ENDPOINT, $this->payload())
            ->assertOk();
    }

    public function test_required_and_coordinate_validation_is_applied(): void
    {
        $this->actingAsCitizen();

        $this->postJson(self::ENDPOINT, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude', 'category_id']);

        $this->postJson(self::ENDPOINT, $this->payload(['latitude' => 90.1, 'longitude' => -180.1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_inactive_or_deleted_category_or_department_is_rejected(): void
    {
        $this->actingAsCitizen();

        $inactiveCategory = ComplaintCategory::factory()->create(['is_active' => false]);
        $this->postJson(self::ENDPOINT, $this->payload(['category_id' => $inactiveCategory->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);

        $inactiveDepartment = Department::factory()->create(['is_active' => false]);
        $categoryInInactiveDepartment = ComplaintCategory::factory()->create(['department_id' => $inactiveDepartment->id]);
        $this->postJson(self::ENDPOINT, $this->payload(['category_id' => $categoryInInactiveDepartment->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);

        $deletedCategory = ComplaintCategory::factory()->create();
        $deletedCategory->delete();
        $this->postJson(self::ENDPOINT, $this->payload(['category_id' => $deletedCategory->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_duplicate_check_success_message_is_localized_to_arabic(): void
    {
        $this->actingAsCitizen();
        [, $category] = $this->referenceData();

        $this->withHeader('Accept-Language', 'ar')
            ->postJson(self::ENDPOINT, $this->payload(['category_id' => $category->id]))
            ->assertOk()
            ->assertJsonPath('message', 'تم التحقق من وجود شكاوى محتملة مكررة بنجاح.');
    }

    public function test_duplicate_check_custom_category_validation_error_is_localized_to_arabic(): void
    {
        $this->actingAsCitizen();
        $inactiveDepartment = Department::factory()->create(['is_active' => false]);
        $category = ComplaintCategory::factory()->create(['department_id' => $inactiveDepartment->id]);

        $this->withHeader('Accept-Language', 'ar')
            ->postJson(self::ENDPOINT, $this->payload(['category_id' => $category->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.category_id.0', 'الفئة المحددة غير صالحة.');
    }

    public function test_it_returns_no_match_when_no_candidate_matches_the_rules(): void
    {
        $this->actingAsCitizen();
        [$department, $category, $priority] = $this->referenceData();
        $otherCategory = ComplaintCategory::factory()->create(['department_id' => $department->id]);

        $this->complaint($department, $otherCategory, $priority, ['latitude' => 33.5138, 'longitude' => 36.2765]);
        $this->complaint($department, $category, $priority, ['latitude' => 33.5142, 'longitude' => 36.2765]);

        foreach (['resolved', 'closed', 'rejected'] as $status) {
            $this->complaint($department, $category, $priority, [
                'status' => $status,
                'latitude' => 33.5138,
                'longitude' => 36.2765,
            ]);
        }

        $this->complaint($department, $category, $priority, ['latitude' => null, 'longitude' => null]);
        $deletedComplaint = $this->complaint($department, $category, $priority, ['latitude' => 33.5138, 'longitude' => 36.2765]);
        $deletedComplaint->delete();

        $this->postJson(self::ENDPOINT, $this->payload(['category_id' => $category->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Duplicate complaint check completed successfully.')
            ->assertJsonPath('data.has_possible_duplicate', false)
            ->assertJsonPath('data.matches', []);
    }

    public function test_zero_coordinates_safely_return_no_matches(): void
    {
        $this->actingAsCitizen();
        [$department, $category, $priority] = $this->referenceData();
        $this->complaint($department, $category, $priority, ['latitude' => 0, 'longitude' => 0]);

        $this->postJson(self::ENDPOINT, $this->payload([
            'latitude' => 0,
            'longitude' => 0,
            'category_id' => $category->id,
        ]))
            ->assertOk()
            ->assertJsonPath('data.has_possible_duplicate', false)
            ->assertJsonPath('data.matches', []);
    }

    public function test_it_detects_every_active_status_within_the_configured_radius(): void
    {
        $this->actingAsCitizen();
        [$department, $category, $priority] = $this->referenceData();

        foreach (['submitted', 'under_review', 'assigned', 'in_progress', 'waiting_citizen', 'escalated'] as $status) {
            $complaint = $this->complaint($department, $category, $priority, [
                'status' => $status,
                'latitude' => 33.513845,
                'longitude' => 36.2765,
            ]);

            $this->postJson(self::ENDPOINT, $this->payload(['category_id' => $category->id]))
                ->assertOk()
                ->assertJsonPath('data.has_possible_duplicate', true)
                ->assertJsonPath('data.matches.0.id', $complaint->id)
                ->assertJsonPath('data.matches.0.status', $status);

            $complaint->delete();
        }
    }

    public function test_matches_are_ordered_limited_and_return_numeric_distances(): void
    {
        config(['gcms.duplicate_complaints.max_results' => 2]);
        $this->actingAsCitizen();
        [$department, $category, $priority] = $this->referenceData();

        $nearest = $this->complaint($department, $category, $priority, ['latitude' => 33.51382, 'longitude' => 36.2765]);
        $middle = $this->complaint($department, $category, $priority, ['latitude' => 33.51385, 'longitude' => 36.2765]);
        $this->complaint($department, $category, $priority, ['latitude' => 33.51389, 'longitude' => 36.2765]);

        $response = $this->postJson(self::ENDPOINT, $this->payload(['category_id' => $category->id]))
            ->assertOk()
            ->assertJsonCount(2, 'data.matches')
            ->assertJsonPath('data.matches.0.id', $nearest->id)
            ->assertJsonPath('data.matches.1.id', $middle->id)
            ->assertJsonStructure(['data' => ['matches' => [[
                'id', 'complaint_number', 'title', 'status', 'category_id', 'distance_meters', 'created_at',
            ]]]]);

        $this->assertIsFloat($response->json('data.matches.0.distance_meters'));
    }

    public function test_radius_configuration_and_distance_boundary_are_respected(): void
    {
        config(['gcms.duplicate_complaints.radius_meters' => 6]);
        $this->actingAsCitizen();
        [$department, $category, $priority] = $this->referenceData();
        $inside = $this->complaint($department, $category, $priority, ['latitude' => 33.51385, 'longitude' => 36.2765]);
        $this->complaint($department, $category, $priority, ['latitude' => 33.5139, 'longitude' => 36.2765]);

        $this->postJson(self::ENDPOINT, $this->payload(['category_id' => $category->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data.matches')
            ->assertJsonPath('data.matches.0.id', $inside->id);
    }

    public function test_response_is_limited_to_public_duplicate_data_and_check_is_read_only(): void
    {
        $this->actingAsCitizen();
        [$department, $category, $priority] = $this->referenceData();
        $complaint = $this->complaint($department, $category, $priority, [
            'latitude' => 33.51382,
            'longitude' => 36.2765,
        ]);
        $originalAttributes = $complaint->fresh()->getAttributes();

        $response = $this->postJson(self::ENDPOINT, $this->payload(['category_id' => $category->id]))
            ->assertOk()
            ->assertJsonMissingPath('data.matches.0.citizen_id')
            ->assertJsonMissingPath('data.matches.0.citizen')
            ->assertJsonMissingPath('data.matches.0.attachments')
            ->assertJsonMissingPath('data.matches.0.assigned_employee')
            ->assertJsonMissingPath('data.matches.0.timeline');

        $this->assertSame(1, Complaint::count());
        $this->assertSame($originalAttributes, $complaint->fresh()->getAttributes());
        $this->assertSame($complaint->id, $response->json('data.matches.0.id'));
    }

    public function test_complaint_creation_is_unchanged_when_a_possible_duplicate_exists(): void
    {
        $citizen = $this->actingAsCitizen();
        [$department, $category, $priority] = $this->referenceData();
        $this->complaint($department, $category, $priority, ['latitude' => 33.51382, 'longitude' => 36.2765]);

        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Another report for the same street light',
            'description' => 'This nearby report must still be created normally.',
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
            'latitude' => 33.5138,
            'longitude' => 36.2765,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonMissingPath('data.duplicate_warning');

        $this->assertDatabaseHas('complaints', [
            'citizen_id' => $citizen->id,
            'title' => 'Another report for the same street light',
        ]);
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
    private function referenceData(): array
    {
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $priority = Priority::factory()->create();

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
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, int|float>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'category_id' => ComplaintCategory::factory()->create()->id,
        ], $overrides);
    }
}

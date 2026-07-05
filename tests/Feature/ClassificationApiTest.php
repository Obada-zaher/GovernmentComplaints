<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\ComplaintClassificationRule;
use App\Models\Department;
use App\Models\OfflineSubmission;
use App\Models\Priority;
use App\Models\User;
use App\Services\Classification\ComplaintClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClassificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_returns_null_prediction_when_no_keyword_matches(): void
    {
        $result = app(ComplaintClassificationService::class)->classify('Unknown issue', 'No matching text here.');

        $this->assertNull($result['department']);
        $this->assertNull($result['category']);
        $this->assertSame(0.0, $result['confidence']);
    }

    public function test_service_predicts_municipality_street_lighting_for_street_light_text(): void
    {
        [$department, $category] = $this->departmentCategory('Municipality', 'municipality', 'Street Lighting', 'municipality-street-lighting');
        $this->rule($department, $category, 'street light', 5);

        $result = app(ComplaintClassificationService::class)->classify('Street light is broken', 'The lamp is not working.');

        $this->assertSame($department->id, $result['department']['id']);
        $this->assertSame($category->id, $result['category']['id']);
    }

    public function test_service_predicts_electricity_power_outage_for_outage_text(): void
    {
        [$department, $category] = $this->departmentCategory('Electricity', 'electricity', 'Power Outage', 'electricity-power-outage');
        $this->rule($department, $category, 'outage', 5);

        $result = app(ComplaintClassificationService::class)->classify('Power outage', 'There is no electricity in the block.');

        $this->assertSame($department->id, $result['department']['id']);
        $this->assertSame($category->id, $result['category']['id']);
    }

    public function test_service_supports_arabic_keywords(): void
    {
        [$department, $category] = $this->departmentCategory('Water', 'water', 'Water Leakage', 'water-water-leakage');
        $this->rule($department, $category, 'تسريب', 5);

        $result = app(ComplaintClassificationService::class)->classify('يوجد تسريب مياه', 'التسريب في الشارع');

        $this->assertSame($department->id, $result['department']['id']);
        $this->assertSame($category->id, $result['category']['id']);
    }

    public function test_confidence_is_zero_when_no_match(): void
    {
        $result = app(ComplaintClassificationService::class)->classify('Nothing', 'No relevant words.');

        $this->assertSame(0.0, $result['confidence']);
    }

    public function test_confidence_is_greater_than_zero_when_keywords_match(): void
    {
        [$department, $category] = $this->departmentCategory();
        $this->rule($department, $category, 'light', 3);

        $result = app(ComplaintClassificationService::class)->classify('Broken light', 'Please repair it.');

        $this->assertGreaterThan(0, $result['confidence']);
    }

    public function test_highest_weighted_category_wins(): void
    {
        [$department, $lighting] = $this->departmentCategory('Municipality', 'municipality', 'Street Lighting', 'municipality-street-lighting');
        $waste = ComplaintCategory::factory()->create(['department_id' => $department->id, 'name' => 'Waste Collection', 'code' => 'municipality-waste-collection']);
        $this->rule($department, $lighting, 'street', 2);
        $this->rule($department, $waste, 'garbage', 8);

        $result = app(ComplaintClassificationService::class)->classify('Street garbage', 'Garbage is blocking the street.');

        $this->assertSame($waste->id, $result['category']['id']);
    }

    public function test_alternatives_are_returned_when_multiple_categories_match(): void
    {
        [$department, $lighting] = $this->departmentCategory('Municipality', 'municipality', 'Street Lighting', 'municipality-street-lighting');
        $waste = ComplaintCategory::factory()->create(['department_id' => $department->id, 'name' => 'Waste Collection', 'code' => 'municipality-waste-collection']);
        $this->rule($department, $lighting, 'light', 5);
        $this->rule($department, $waste, 'garbage', 3);

        $result = app(ComplaintClassificationService::class)->classify('Light near garbage', 'The street light and garbage bin need attention.');

        $this->assertNotEmpty($result['alternatives']);
    }

    public function test_unauthenticated_user_cannot_preview(): void
    {
        $this->postJson('/api/v1/classification/complaints/preview', $this->previewPayload())
            ->assertUnauthorized();
    }

    public function test_authenticated_citizen_can_preview(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        [$department, $category] = $this->departmentCategory();
        $this->rule($department, $category, 'lamp', 5);

        $this->postJson('/api/v1/classification/complaints/preview', $this->previewPayload())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.category.id', $category->id);
    }

    public function test_authenticated_admin_can_preview(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/classification/complaints/preview', $this->previewPayload())
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_preview_returns_method_and_confidence(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());

        $this->postJson('/api/v1/classification/complaints/preview', $this->previewPayload())
            ->assertOk()
            ->assertJsonStructure(['data' => ['method', 'confidence']]);
    }

    public function test_admin_can_list_rules(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        [$department, $category] = $this->departmentCategory();
        $rule = $this->rule($department, $category, 'light', 5);

        $this->getJson('/api/v1/admin/classification-rules')
            ->assertOk()
            ->assertJsonPath('data.classification_rules.0.id', $rule->id);
    }

    public function test_admin_can_create_rule(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        [$department, $category] = $this->departmentCategory();

        $this->postJson('/api/v1/admin/classification-rules', [
            'department_id' => $department->id,
            'category_id' => $category->id,
            'keyword' => 'broken lamp',
            'weight' => 5,
            'is_active' => true,
            'language' => 'en',
            'notes' => 'Street lighting keyword',
        ])->assertCreated()
            ->assertJsonPath('data.keyword', 'broken lamp')
            ->assertJsonPath('data.normalized_keyword', 'broken lamp');
    }

    public function test_admin_can_update_rule(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        [$department, $category] = $this->departmentCategory();
        $rule = $this->rule($department, $category, 'lamp', 3);

        $this->putJson("/api/v1/admin/classification-rules/{$rule->id}", [
            'keyword' => 'broken lamp',
            'weight' => 8,
        ])->assertOk()
            ->assertJsonPath('data.keyword', 'broken lamp')
            ->assertJsonPath('data.weight', 8);
    }

    public function test_admin_can_delete_rule(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        [$department, $category] = $this->departmentCategory();
        $rule = $this->rule($department, $category, 'lamp', 3);

        $this->deleteJson("/api/v1/admin/classification-rules/{$rule->id}")
            ->assertOk();

        $this->assertDatabaseMissing('complaint_classification_rules', ['id' => $rule->id]);
    }

    public function test_citizen_cannot_create_rule(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());

        $this->postJson('/api/v1/admin/classification-rules', [
            'keyword' => 'lamp',
            'weight' => 5,
        ])->assertForbidden();
    }

    public function test_employee_cannot_create_rule(): void
    {
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->postJson('/api/v1/admin/classification-rules', [
            'keyword' => 'lamp',
            'weight' => 5,
        ])->assertForbidden();
    }

    public function test_category_department_mismatch_returns_validation_error(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $otherDepartment->id]);

        $this->postJson('/api/v1/admin/classification-rules', [
            'department_id' => $department->id,
            'category_id' => $category->id,
            'keyword' => 'lamp',
            'weight' => 5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_complaint_without_category_is_auto_classified_when_confidence_is_high(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        [$department, $category] = $this->departmentCategory();
        $this->rule($department, $category, 'lamp', 5);
        Priority::factory()->create(['code' => 'medium']);

        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Broken lamp',
            'description' => 'The lamp is not working.',
        ])->assertCreated()
            ->assertJsonPath('data.department.id', $department->id)
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.classification.auto_assigned', true);
    }

    public function test_complaint_with_explicit_category_is_not_overwritten(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        [$department, $predictedCategory] = $this->departmentCategory();
        $explicitCategory = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $this->rule($department, $predictedCategory, 'lamp', 5);
        Priority::factory()->create(['code' => 'medium']);

        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Broken lamp',
            'description' => 'The lamp is not working.',
            'category_id' => $explicitCategory->id,
        ])->assertCreated()
            ->assertJsonPath('data.category.id', $explicitCategory->id);
    }

    public function test_classification_confidence_is_stored(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        [$department, $category] = $this->departmentCategory();
        $this->rule($department, $category, 'lamp', 5);
        Priority::factory()->create(['code' => 'medium']);

        $response = $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'Broken lamp',
            'description' => 'The lamp is not working.',
        ])->assertCreated();

        $this->assertDatabaseHas('complaints', [
            'id' => $response->json('data.id'),
            'classification_confidence' => 100,
        ]);
    }

    public function test_offline_sync_uses_classification_when_category_missing(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        [$department, $category] = $this->departmentCategory();
        $this->rule($department, $category, 'lamp', 5);
        Priority::factory()->create(['code' => 'medium']);

        $this->postJson('/api/v1/citizen/offline/complaints/sync', [
            'client_uuid' => 'classification-offline-uuid',
            'title' => 'Broken lamp',
            'description' => 'The lamp is not working.',
            'source' => 'offline_sync',
        ])->assertCreated()
            ->assertJsonPath('data.complaint.category.id', $category->id);
    }

    public function test_duplicate_offline_sync_does_not_create_duplicate_complaint(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        [$department, $category] = $this->departmentCategory();
        $this->rule($department, $category, 'lamp', 5);
        Priority::factory()->create(['code' => 'medium']);
        $payload = [
            'client_uuid' => 'classification-offline-uuid',
            'title' => 'Broken lamp',
            'description' => 'The lamp is not working.',
            'source' => 'offline_sync',
        ];

        $this->postJson('/api/v1/citizen/offline/complaints/sync', $payload)->assertCreated();
        $this->postJson('/api/v1/citizen/offline/complaints/sync', $payload)
            ->assertOk()
            ->assertJsonPath('meta.idempotent', true);

        $this->assertSame(1, Complaint::query()->where('client_uuid', 'classification-offline-uuid')->count());
        $this->assertSame(1, OfflineSubmission::query()->where('client_uuid', 'classification-offline-uuid')->count());
    }

    /**
     * @return array{0: Department, 1: ComplaintCategory}
     */
    private function departmentCategory(
        string $departmentName = 'Municipality',
        string $departmentCode = 'municipality',
        string $categoryName = 'Street Lighting',
        string $categoryCode = 'municipality-street-lighting',
    ): array {
        $department = Department::factory()->create([
            'name' => $departmentName,
            'code' => $departmentCode,
        ]);
        $category = ComplaintCategory::factory()->create([
            'department_id' => $department->id,
            'name' => $categoryName,
            'code' => $categoryCode,
        ]);

        return [$department, $category];
    }

    private function rule(Department $department, ComplaintCategory $category, string $keyword, int $weight): ComplaintClassificationRule
    {
        $classifier = app(ComplaintClassificationService::class);

        return ComplaintClassificationRule::factory()->create([
            'department_id' => $department->id,
            'category_id' => $category->id,
            'keyword' => $keyword,
            'normalized_keyword' => $classifier->normalize($keyword),
            'weight' => $weight,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function previewPayload(): array
    {
        return [
            'title' => 'Street light is broken',
            'description' => 'The lamp in our street has not worked for three days.',
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Models\ComplaintCategory;
use App\Models\ComplaintClassificationRule;
use App\Models\User;
use Database\Seeders\ClassificationRuleSeeder;
use Database\Seeders\ComplaintCategoriesSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\PrioritiesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SeededArabicClassificationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_rules_classify_ambiguous_arabic_complaints_by_their_subject(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        $this->seedClassificationData();

        foreach ([
            ['عمود انارة', 'يوجد عمود انارة معطل في شارع رئيسي', 'municipality', 'municipality-street-lighting'],
            ['عامود الإنارة لا يعمل', 'عامود الإنارة لا يعمل في الطريق', 'municipality', 'municipality-street-lighting'],
            ['تسريب مياه', 'يوجد تسريب مياه في الشارع', 'water', 'water-water-leakage'],
            ['النفايات متراكمة', 'النفايات متراكمة في الشارع', 'municipality', 'municipality-waste-collection'],
            ['سلك كهربائي مكشوف', 'سلك كهربائي مكشوف في الطريق', 'electricity', 'electricity-dangerous-electrical-wire'],
            ['حفرة كبيرة', 'حفرة كبيرة في الشارع', 'municipality', 'municipality-road-damage'],
            ['إشارة المرور معطلة', 'إشارة المرور معطلة في الشارع', 'transportation', 'transportation-traffic-signal-issue'],
            ['الكهرباء مقطوعة', 'الكهرباء مقطوعة في الحي', 'electricity', 'electricity-power-outage'],
            ['لا يوجد ماء', 'لا يوجد ماء منذ الصباح', 'water', 'water-water-interruption'],
            ['الباصات', 'الباصات لا تصل إلى المنطقة', 'transportation', 'transportation-public-transport-complaint'],
            ['الطبيب', 'الطبيب لم يحضر إلى العيادة', 'health', 'health-clinic-service-complaint'],
            ['تسمم غذائي', 'هناك حالات تسمم غذائي', 'health', 'health-public-health-issue'],
        ] as [$title, $description, $departmentCode, $categoryCode]) {
            $response = $this->postJson('/api/v1/classification/complaints/preview', [
                'title' => $title,
                'description' => $description,
            ])->assertOk();

            $this->assertSame($departmentCode, $response->json('data.department.code'), $title);
            $this->assertSame($categoryCode, $response->json('data.category.code'), $title);
            $this->assertSame('rule_based_weighted_keywords', $response->json('data.method'), $title);
            $this->assertGreaterThanOrEqual(60, $response->json('data.confidence'), $title);
        }
    }

    public function test_weak_location_only_evidence_returns_no_reliable_classification(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        $this->seedClassificationData();

        $this->postJson('/api/v1/classification/complaints/preview', [
            'title' => 'مشكلة',
            'description' => 'يوجد شيء في شارع رئيسي',
        ])->assertOk()
            ->assertJsonPath('data.department', null)
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.confidence', 0);
    }

    public function test_generic_entity_words_do_not_cross_the_auto_assignment_threshold(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        $this->seedClassificationData();

        foreach ([
            ['عمود', 'يوجد عمود مائل'],
            ['مشكلة مياه', 'هناك مشكلة في المياه'],
            ['الكهرباء', 'مشكلة في الكهرباء'],
            ['ضوء', 'يوجد ضوء ضعيف'],
            ['مشكلة في الطريق', 'الطريق الرئيسي'],
        ] as [$title, $description]) {
            $response = $this->postJson('/api/v1/classification/complaints/preview', [
                'title' => $title,
                'description' => $description,
            ])->assertOk();

            $this->assertLessThan(60, $response->json('data.confidence'), $title);
        }
    }

    public function test_preview_contract_and_arabic_localization_remain_unchanged(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        $this->seedClassificationData();

        $this->postJson('/api/v1/classification/complaints/preview', [
            'title' => 'عمود انارة',
            'description' => 'عمود انارة معطل في الشارع',
        ], ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('message', 'تم تصنيف الشكوى بنجاح.')
            ->assertJsonPath('data.category.name', 'إنارة الشوارع')
            ->assertJsonStructure(['data' => [
                'department',
                'category',
                'confidence',
                'matched_keywords',
                'alternatives',
                'method',
            ]])
            ->assertJsonMissingPath('data.scores')
            ->assertJsonMissingPath('data.used_rules');
    }

    public function test_complaint_creation_auto_assigns_the_correct_seeded_category_without_overwriting_an_explicit_one(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        $this->seedClassificationData();
        $roadDamage = ComplaintCategory::query()->where('code', 'municipality-road-damage')->firstOrFail();

        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'مشكلة مياه',
            'description' => 'هناك مشكلة في المياه',
        ])->assertCreated()
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.classification.auto_assigned', false);

        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'عمود انارة',
            'description' => 'يوجد عمود انارة معطل في شارع رئيسي',
        ])->assertCreated()
            ->assertJsonPath('data.category.code', 'municipality-street-lighting')
            ->assertJsonPath('data.department.code', 'municipality')
            ->assertJsonPath('data.classification.auto_assigned', true);

        $this->postJson('/api/v1/citizen/complaints', [
            'title' => 'عمود انارة',
            'description' => 'يوجد عمود انارة معطل في شارع رئيسي',
            'category_id' => $roadDamage->id,
        ])->assertCreated()
            ->assertJsonPath('data.category.id', $roadDamage->id)
            ->assertJsonPath('data.classification.auto_assigned', false);
    }

    public function test_offline_sync_uses_the_correct_seeded_arabic_classification(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());
        $this->seedClassificationData();

        $this->postJson('/api/v1/citizen/offline/complaints/sync', [
            'client_uuid' => 'seeded-arabic-classification-offline',
            'title' => 'عمود انارة',
            'description' => 'يوجد عمود انارة معطل في شارع رئيسي',
            'source' => 'offline_sync',
        ])->assertCreated()
            ->assertJsonPath('data.complaint.category.code', 'municipality-street-lighting');
    }

    public function test_classification_rule_seeder_updates_existing_rules_without_creating_duplicates(): void
    {
        $this->seedClassificationData();
        $firstCount = ComplaintClassificationRule::query()->count();

        $this->seed(ClassificationRuleSeeder::class);

        $this->assertSame($firstCount, ComplaintClassificationRule::query()->count());
    }

    private function seedClassificationData(): void
    {
        $this->seed([
            DepartmentsSeeder::class,
            ComplaintCategoriesSeeder::class,
            PrioritiesSeeder::class,
            ClassificationRuleSeeder::class,
        ]);
    }
}

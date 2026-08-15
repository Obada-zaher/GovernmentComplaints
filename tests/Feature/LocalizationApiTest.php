<?php

namespace Tests\Feature;

use App\Jobs\Notifications\SendComplaintEmailNotificationJob;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\ComplaintClassificationRule;
use App\Models\Department;
use App\Models\Priority;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Classification\ComplaintClassificationService;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocalizationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_accept_language_parses_supported_variants_quality_values_and_fallbacks(): void
    {
        Department::factory()->create([
            'name' => 'Municipality',
            'name_ar' => 'البلدية',
            'code' => 'municipality',
        ]);

        foreach (['ar', 'ar-SY', 'ar-SA', 'ar-SY,ar;q=0.9,en;q=0.8'] as $header) {
            $this->withHeader('Accept-Language', $header)
                ->getJson('/api/v1/lookups/departments')
                ->assertOk()
                ->assertJsonPath('data.departments.0.name', 'البلدية');
        }

        foreach (['en', 'en-US', 'en-GB', 'fr', '%%%', 'ar;q=0'] as $header) {
            $this->withHeader('Accept-Language', $header)
                ->getJson('/api/v1/lookups/departments')
                ->assertOk()
                ->assertJsonPath('data.departments.0.name', 'Municipality');
        }

        $this->getJson('/api/v1/lookups/departments')
            ->assertOk()
            ->assertJsonPath('data.departments.0.name', 'Municipality');

        config(['app.locale' => 'ar']);
        $this->getJson('/api/v1/lookups/departments')
            ->assertOk()
            ->assertJsonPath('data.departments.0.name', 'البلدية');
        config(['app.locale' => 'en']);
    }

    public function test_lookup_names_localize_without_changing_contract_or_machine_values(): void
    {
        $department = Department::factory()->create([
            'name' => 'Electricity',
            'name_ar' => 'الكهرباء',
            'code' => 'electricity',
        ]);
        ComplaintCategory::factory()->create([
            'department_id' => $department->id,
            'name' => 'Power Outage',
            'name_ar' => 'انقطاع الكهرباء',
            'code' => 'electricity-power-outage',
        ]);
        Priority::factory()->create([
            'name' => 'Urgent',
            'name_ar' => 'عاجلة',
            'code' => 'urgent',
            'level' => 4,
            'color' => '#ef4444',
        ]);

        foreach (['departments', 'categories', 'priorities'] as $lookup) {
            $english = $this->withHeader('Accept-Language', 'en')->getJson("/api/v1/lookups/{$lookup}")->assertOk()->json();
            $arabic = $this->withHeader('Accept-Language', 'ar')->getJson("/api/v1/lookups/{$lookup}")->assertOk()->json();

            $this->assertSame($this->keyShape($english), $this->keyShape($arabic));
        }

        $englishCategory = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/lookups/categories')->json('data.categories.0');
        $arabicCategory = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/lookups/categories')->json('data.categories.0');
        $this->assertSame($englishCategory['id'], $arabicCategory['id']);
        $this->assertSame($englishCategory['department_id'], $arabicCategory['department_id']);
        $this->assertSame($englishCategory['code'], $arabicCategory['code']);
        $this->assertSame('Power Outage', $englishCategory['name']);
        $this->assertSame('انقطاع الكهرباء', $arabicCategory['name']);

        $arabicPriority = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/lookups/priorities');
        $arabicPriority->assertJsonPath('data.priorities.0.code', 'urgent')
            ->assertJsonPath('data.priorities.0.level', 4)
            ->assertJsonPath('data.priorities.0.color', '#ef4444')
            ->assertJsonPath('data.priorities.0.name', 'عاجلة');
    }

    public function test_complaint_localizes_only_system_display_text_and_keeps_the_same_shape(): void
    {
        $department = Department::factory()->create([
            'name' => 'Municipality',
            'name_ar' => 'البلدية',
            'code' => 'municipality',
        ]);
        $category = ComplaintCategory::factory()->create([
            'department_id' => $department->id,
            'name' => 'Road Damage',
            'name_ar' => 'أضرار الطرق',
            'code' => 'municipality-road-damage',
        ]);
        $priority = Priority::factory()->create([
            'name' => 'High',
            'name_ar' => 'عالية',
            'code' => 'high',
        ]);
        $citizen = User::factory()->citizen()->create();
        $complaint = Complaint::factory()->create([
            'citizen_id' => $citizen->id,
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
            'title' => 'User supplied title',
            'description' => 'نص أدخله المستخدم كما هو',
            'status' => 'submitted',
        ]);
        $complaint->statusHistories()->create([
            'changed_by' => $citizen->id,
            'from_status' => null,
            'to_status' => 'submitted',
            'note' => 'Complaint submitted by citizen',
        ]);
        Sanctum::actingAs($citizen);

        $english = $this->withHeader('Accept-Language', 'en')->getJson("/api/v1/citizen/complaints/{$complaint->id}")->assertOk()->json();
        $arabic = $this->withHeader('Accept-Language', 'ar-SY')->getJson("/api/v1/citizen/complaints/{$complaint->id}")->assertOk()->json();

        $this->assertSame($this->keyShape($english), $this->keyShape($arabic));
        foreach (['id', 'complaint_number', 'title', 'description', 'status', 'due_at', 'created_at'] as $field) {
            $this->assertSame($english['data'][$field], $arabic['data'][$field]);
        }
        $this->assertSame('submitted', $english['data']['status']);
        $this->assertSame('submitted', $arabic['data']['status']);
        $this->assertSame($english['data']['department']['id'], $arabic['data']['department']['id']);
        $this->assertSame($english['data']['department']['code'], $arabic['data']['department']['code']);
        $this->assertSame('Municipality', $english['data']['department']['name']);
        $this->assertSame('البلدية', $arabic['data']['department']['name']);
        $this->assertSame('Complaint submitted by citizen', $english['data']['timeline'][0]['note']);
        $this->assertSame('تم تقديم الشكوى من المواطن', $arabic['data']['timeline'][0]['note']);
    }

    public function test_arabic_english_and_mixed_classification_are_independent_of_ui_locale(): void
    {
        [$electricity, $powerOutage] = $this->classificationEntities(
            'Electricity',
            'الكهرباء',
            'electricity',
            'Power Outage',
            'انقطاع الكهرباء',
            'electricity-power-outage',
            'انقطاع الكهرباء',
        );
        [$municipality, $roadDamage] = $this->classificationEntities(
            'Municipality',
            'البلدية',
            'municipality',
            'Road Damage',
            'أضرار الطرق',
            'municipality-road-damage',
            'حفرة',
        );
        $citizen = User::factory()->citizen()->create();
        Sanctum::actingAs($citizen);

        $arabicUnderEnglishUi = $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/classification/complaints/preview', [
            'title' => 'اِنْقِطَاعُ الْكَهْرَبَاءِ',
            'description' => 'يوجد انقطاع في الكهرباء منذ الصباح',
        ])->assertOk();
        $arabicUnderEnglishUi->assertJsonPath('data.department.id', $electricity->id)
            ->assertJsonPath('data.department.code', 'electricity')
            ->assertJsonPath('data.department.name', 'Electricity')
            ->assertJsonPath('data.method', ComplaintClassificationService::METHOD);

        $englishUnderArabicUi = $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/classification/complaints/preview', [
            'title' => 'Power outage',
            'description' => 'Electricity outage in the neighborhood',
        ])->assertOk();
        $englishUnderArabicUi->assertJsonPath('data.department.id', $electricity->id)
            ->assertJsonPath('data.department.code', 'electricity')
            ->assertJsonPath('data.department.name', 'الكهرباء');

        $mixed = $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/classification/complaints/preview', [
            'title' => 'حفرة كبيرة pothole',
            'description' => 'توجد حفرة في الطريق',
        ])->assertOk();
        $mixed->assertJsonPath('data.department.id', $municipality->id)
            ->assertJsonPath('data.category.id', $roadDamage->id);

        $classifier = app(ComplaintClassificationService::class);
        $this->assertSame('اشارة مرور معطلة', $classifier->normalize('إِشَارَةُ مـرور مُعطّلة'));
        $this->assertSame($this->keyShape($arabicUnderEnglishUi->json()), $this->keyShape($englishUnderArabicUi->json()));
        $this->assertSame($powerOutage->id, $arabicUnderEnglishUi->json('data.category.id'));
    }

    public function test_validation_and_authentication_errors_localize_without_changing_keys_or_statuses(): void
    {
        $englishValidation = $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/auth/login', [])->assertUnprocessable()->json();
        $arabicValidation = $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/auth/login', [])->assertUnprocessable()->json();

        $this->assertSame($this->keyShape($englishValidation), $this->keyShape($arabicValidation));
        $this->assertSame(array_keys($englishValidation['errors']), array_keys($arabicValidation['errors']));
        $this->assertSame('Validation failed.', $englishValidation['message']);
        $this->assertSame('فشل التحقق من صحة البيانات.', $arabicValidation['message']);
        $this->assertStringContainsString('مطلوب', $arabicValidation['errors']['login'][0]);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/citizen/complaints')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'غير مصادق عليه.');
    }

    public function test_notifications_and_reports_localize_display_text_with_identical_contracts_and_numbers(): void
    {
        $department = Department::factory()->create([
            'name' => 'Water',
            'name_ar' => 'المياه',
            'code' => 'water',
        ]);
        $citizen = User::factory()->citizen()->create();
        $complaint = Complaint::factory()->create([
            'citizen_id' => $citizen->id,
            'department_id' => $department->id,
            'title' => 'Original complaint title',
            'status' => 'submitted',
        ]);
        UserNotification::factory()->create([
            'user_id' => $citizen->id,
            'complaint_id' => $complaint->id,
            'type' => 'complaint_status_updated',
            'title' => 'Complaint status updated',
            'body' => "Complaint {$complaint->complaint_number} status is now submitted.",
            'data' => ['complaint_id' => $complaint->id, 'status' => 'submitted'],
        ]);
        Sanctum::actingAs($citizen);

        $englishNotifications = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/notifications')->assertOk()->json();
        $arabicNotifications = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/notifications')->assertOk()->json();
        $this->assertSame($this->keyShape($englishNotifications), $this->keyShape($arabicNotifications));
        $this->assertSame('complaint_status_updated', $arabicNotifications['data']['notifications'][0]['type']);
        $this->assertSame($englishNotifications['data']['notifications'][0]['data'], $arabicNotifications['data']['notifications'][0]['data']);
        $this->assertSame('تم تحديث حالة الشكوى', $arabicNotifications['data']['notifications'][0]['title']);
        $this->assertSame('Original complaint title', $arabicNotifications['data']['notifications'][0]['complaint']['title']);

        Sanctum::actingAs(User::factory()->admin()->create());
        $englishReport = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/admin/reports/complaints-by-department')->assertOk()->json();
        $arabicReport = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/admin/reports/complaints-by-department')->assertOk()->json();
        $this->assertSame($this->keyShape($englishReport), $this->keyShape($arabicReport));
        $englishWater = collect($englishReport['data'])->firstWhere('department.code', 'water');
        $arabicWater = collect($arabicReport['data'])->firstWhere('department.code', 'water');
        $this->assertSame($englishWater['total'], $arabicWater['total']);
        $this->assertSame($englishWater['department']['code'], $arabicWater['department']['code']);
        $this->assertSame('Water', $englishWater['department']['name']);
        $this->assertSame('المياه', $arabicWater['department']['name']);
    }

    public function test_queued_notification_preserves_the_request_locale(): void
    {
        Queue::fake();
        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/health')->assertOk();
        $user = User::factory()->employee()->create();
        $complaint = Complaint::factory()->create();

        $notification = app(NotificationService::class)->notifyUser(
            $user,
            NotificationService::TYPE_COMPLAINT_ASSIGNED,
            $complaint,
            'Complaint assigned to you',
            "Complaint {$complaint->complaint_number} has been assigned to you.",
        );

        Queue::assertPushed(SendComplaintEmailNotificationJob::class, function (SendComplaintEmailNotificationJob $job): bool {
            return $job->locale === 'ar'
                && $job->title === 'تم تعيين شكوى لك'
                && str_starts_with((string) $job->body, 'تم تعيين الشكوى');
        });
        $this->assertSame('Complaint assigned to you', $notification?->title);
        $this->assertSame(
            "Complaint {$complaint->complaint_number} has been assigned to you.",
            $notification?->body,
        );
    }

    /**
     * @return array{0: Department, 1: ComplaintCategory}
     */
    private function classificationEntities(
        string $departmentName,
        string $departmentNameAr,
        string $departmentCode,
        string $categoryName,
        string $categoryNameAr,
        string $categoryCode,
        string $keyword,
    ): array {
        $department = Department::factory()->create([
            'name' => $departmentName,
            'name_ar' => $departmentNameAr,
            'code' => $departmentCode,
        ]);
        $category = ComplaintCategory::factory()->create([
            'department_id' => $department->id,
            'name' => $categoryName,
            'name_ar' => $categoryNameAr,
            'code' => $categoryCode,
        ]);
        $classifier = app(ComplaintClassificationService::class);
        ComplaintClassificationRule::factory()->create([
            'department_id' => $department->id,
            'category_id' => $category->id,
            'keyword' => $keyword,
            'normalized_keyword' => $classifier->normalize($keyword),
            'weight' => 5,
            'is_active' => true,
        ]);

        if ($departmentCode === 'electricity') {
            ComplaintClassificationRule::factory()->create([
                'department_id' => $department->id,
                'category_id' => $category->id,
                'keyword' => 'power outage',
                'normalized_keyword' => 'power outage',
                'weight' => 5,
                'is_active' => true,
            ]);
        }

        return [$department, $category];
    }

    private function keyShape(mixed $value): mixed
    {
        if (! is_array($value)) {
            return get_debug_type($value);
        }

        if (array_is_list($value)) {
            return $value === [] ? [] : [$this->keyShape($value[0])];
        }

        $shape = [];
        foreach ($value as $key => $nestedValue) {
            $shape[$key] = $this->keyShape($nestedValue);
        }

        return $shape;
    }
}

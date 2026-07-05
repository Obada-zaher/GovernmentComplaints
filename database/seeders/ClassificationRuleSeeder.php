<?php

namespace Database\Seeders;

use App\Models\ComplaintCategory;
use App\Models\ComplaintClassificationRule;
use App\Models\Department;
use App\Services\Classification\ComplaintClassificationService;
use Illuminate\Database\Seeder;

class ClassificationRuleSeeder extends Seeder
{
    public function run(): void
    {
        $classifier = app(ComplaintClassificationService::class);

        $rules = [
            'municipality' => [
                'municipality-road-damage' => ['road', 'street', 'pothole', 'asphalt', 'حفرة', 'طريق', 'شارع', 'رصيف'],
                'municipality-waste-collection' => ['garbage', 'waste', 'trash', 'قمامة', 'نفايات', 'حاوية'],
                'municipality-street-lighting' => ['light', 'lamp', 'lighting', 'street light', 'إنارة', 'ضوء', 'عامود'],
            ],
            'electricity' => [
                'electricity-power-outage' => ['electricity', 'power', 'outage', 'blackout', 'كهرباء', 'انقطاع', 'تيار'],
                'electricity-dangerous-electrical-wire' => ['wire', 'cable', 'danger', 'exposed wire', 'سلك', 'كبل', 'خطر'],
            ],
            'water' => [
                'water-water-leakage' => ['leakage', 'water leak', 'pipe', 'تسريب', 'مياه', 'ماسورة'],
                'water-water-interruption' => ['water cut', 'no water', 'interruption', 'انقطاع المياه', 'لا يوجد ماء'],
            ],
            'transportation' => [
                'transportation-traffic-signal-issue' => ['traffic light', 'signal', 'اشارة', 'مرور', 'إشارة مرور'],
                'transportation-public-transport-complaint' => ['bus', 'transport', 'taxi', 'باص', 'نقل', 'مواصلات'],
            ],
            'health' => [
                'health-clinic-service-complaint' => ['clinic', 'doctor', 'hospital', 'health center', 'عيادة', 'طبيب', 'مشفى', 'مركز صحي'],
                'health-public-health-issue' => ['infection', 'food poisoning', 'public health', 'تلوث', 'تسمم', 'صحة عامة'],
            ],
        ];

        foreach ($rules as $departmentCode => $categories) {
            $department = Department::query()->where('code', $departmentCode)->first();

            if (! $department) {
                continue;
            }

            foreach ($categories as $categoryCode => $keywords) {
                $category = ComplaintCategory::query()->where('code', $categoryCode)->first();

                if (! $category) {
                    continue;
                }

                foreach ($keywords as $keyword) {
                    ComplaintClassificationRule::query()->updateOrCreate(
                        [
                            'department_id' => $department->id,
                            'category_id' => $category->id,
                            'keyword' => $keyword,
                        ],
                        [
                            'weight' => str_contains($keyword, ' ') ? 5 : 3,
                            'is_active' => true,
                            'language' => preg_match('/\p{Arabic}/u', $keyword) ? 'ar' : 'en',
                            'normalized_keyword' => $classifier->normalize($keyword),
                            'notes' => 'Seeded rule-based classifier keyword.',
                        ],
                    );
                }
            }
        }
    }
}

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
                'municipality-road-damage' => [
                    'road' => 1, 'street' => 1, 'pothole' => 8, 'asphalt' => 6,
                    'حفرة' => 8, 'حفر' => 7, 'تشققات' => 7, 'اسفلت' => 6, 'طريق' => 1, 'شارع' => 1, 'رصيف' => 5,
                    'road damage' => 9, 'damaged road' => 9, 'طريق متضرر' => 9, 'شارع متضرر' => 9,
                    'رصيف مكسور' => 9, 'هبوط' => 7, 'انهيار' => 8, 'تلف الطريق' => 9,
                ],
                'municipality-waste-collection' => [
                    'garbage' => 6, 'waste' => 6, 'trash' => 6, 'قمامة' => 7, 'نفايات' => 7, 'حاوية' => 5,
                    'waste collection' => 8, 'garbage collection' => 8, 'نفايات متراكمة' => 9, 'حاوية ممتلئة' => 8,
                ],
                'municipality-street-lighting' => [
                    'light' => 5, 'lamp' => 6, 'lighting' => 6, 'street light' => 10, 'broken lamp' => 9,
                    'إنارة' => 7, 'عمود' => 6, 'عامود' => 6, 'مصباح' => 6, 'لمبة' => 6, 'كشاف' => 6, 'ضوء' => 3,
                    'عمود انارة' => 10, 'عامود انارة' => 10,
                    'انارة الشوارع' => 10, 'انارة شارع' => 9,
                    'ضوء شارع' => 9, 'شارع مظلم' => 9, 'عمود لا يعمل' => 10, 'عمود معطل' => 10, 'مصباح شارع' => 10,
                ],
            ],
            'electricity' => [
                'electricity-power-outage' => [
                    'electricity' => 6, 'power' => 5, 'outage' => 7, 'blackout' => 8, 'كهرباء' => 7, 'انقطاع' => 1, 'تيار' => 4,
                    'power outage' => 10, 'electricity outage' => 10, 'انقطاع الكهرباء' => 10, 'كهرباء مقطوعة' => 10,
                ],
                'electricity-dangerous-electrical-wire' => [
                    'wire' => 6, 'cable' => 6, 'danger' => 1, 'exposed wire' => 10, 'سلك' => 6, 'كبل' => 6, 'خطر' => 1,
                    'سلك كهربائي' => 9, 'سلك مكشوف' => 10, 'سلك كهربائي مكشوف' => 10,
                ],
            ],
            'water' => [
                'water-water-leakage' => [
                    'leakage' => 7, 'water leak' => 10, 'pipe' => 5, 'تسريب' => 8, 'مياه' => 4, 'ماسورة' => 6,
                    'تسريب مياه' => 10, 'تسرب مياه' => 10, 'ماسورة مكسورة' => 9,
                ],
                'water-water-interruption' => [
                    'water cut' => 10, 'no water' => 10, 'interruption' => 5, 'انقطاع المياه' => 10, 'لا يوجد ماء' => 10,
                    'المياه مقطوعة' => 10,
                ],
            ],
            'transportation' => [
                'transportation-traffic-signal-issue' => [
                    'traffic light' => 10, 'signal' => 6, 'اشارة' => 5, 'مرور' => 1, 'إشارة مرور' => 10,
                    'إشارة معطلة' => 9,
                ],
                'transportation-public-transport-complaint' => [
                    'bus' => 7, 'transport' => 5, 'taxi' => 6, 'باص' => 8, 'نقل' => 3, 'مواصلات' => 7,
                    'public transport' => 9, 'الباصات' => 8,
                ],
            ],
            'health' => [
                'health-clinic-service-complaint' => [
                    'clinic' => 6, 'doctor' => 7, 'hospital' => 6, 'health center' => 8, 'عيادة' => 7, 'طبيب' => 7, 'مشفى' => 6, 'مركز صحي' => 8,
                    'الطبيب لم يحضر' => 10,
                ],
                'health-public-health-issue' => [
                    'infection' => 7, 'food poisoning' => 10, 'public health' => 8, 'تلوث' => 7, 'تسمم' => 8, 'صحة عامة' => 8,
                    'تسمم غذائي' => 10,
                ],
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

                foreach ($keywords as $keyword => $weight) {
                    ComplaintClassificationRule::query()->updateOrCreate(
                        [
                            'department_id' => $department->id,
                            'category_id' => $category->id,
                            'keyword' => $keyword,
                        ],
                        [
                            'weight' => $weight,
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

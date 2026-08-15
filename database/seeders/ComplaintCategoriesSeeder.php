<?php

namespace Database\Seeders;

use App\Models\ComplaintCategory;
use App\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ComplaintCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $categoriesByDepartment = [
            'municipality' => [
                'Road Damage' => 'أضرار الطرق',
                'Waste Collection' => 'جمع النفايات',
                'Street Lighting' => 'إنارة الشوارع',
            ],
            'electricity' => [
                'Power Outage' => 'انقطاع الكهرباء',
                'Dangerous Electrical Wire' => 'أسلاك كهربائية خطرة',
            ],
            'water' => [
                'Water Leakage' => 'تسرب المياه',
                'Water Interruption' => 'انقطاع المياه',
            ],
            'transportation' => [
                'Traffic Signal Issue' => 'مشكلة في الإشارات المرورية',
                'Public Transport Complaint' => 'شكوى النقل العام',
            ],
            'health' => [
                'Clinic Service Complaint' => 'شكوى خدمات العيادات',
                'Public Health Issue' => 'مشكلة صحة عامة',
            ],
        ];

        foreach ($categoriesByDepartment as $departmentCode => $categoryNames) {
            $department = Department::query()->where('code', $departmentCode)->firstOrFail();

            foreach ($categoryNames as $categoryName => $categoryNameAr) {
                ComplaintCategory::query()->updateOrCreate(
                    ['code' => $departmentCode.'-'.Str::slug($categoryName)],
                    [
                        'department_id' => $department->id,
                        'name' => $categoryName,
                        'name_ar' => $categoryNameAr,
                        'description' => null,
                        'description_ar' => null,
                        'keywords' => collect(explode(' ', Str::lower($categoryName)))->values()->all(),
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}

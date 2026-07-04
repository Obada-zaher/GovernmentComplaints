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
                'Road Damage',
                'Waste Collection',
                'Street Lighting',
            ],
            'electricity' => [
                'Power Outage',
                'Dangerous Electrical Wire',
            ],
            'water' => [
                'Water Leakage',
                'Water Interruption',
            ],
            'transportation' => [
                'Traffic Signal Issue',
                'Public Transport Complaint',
            ],
            'health' => [
                'Clinic Service Complaint',
                'Public Health Issue',
            ],
        ];

        foreach ($categoriesByDepartment as $departmentCode => $categoryNames) {
            $department = Department::query()->where('code', $departmentCode)->firstOrFail();

            foreach ($categoryNames as $categoryName) {
                ComplaintCategory::query()->updateOrCreate(
                    ['code' => $departmentCode.'-'.Str::slug($categoryName)],
                    [
                        'department_id' => $department->id,
                        'name' => $categoryName,
                        'description' => null,
                        'keywords' => collect(explode(' ', Str::lower($categoryName)))->values()->all(),
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}

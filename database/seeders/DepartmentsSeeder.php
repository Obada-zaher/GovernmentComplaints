<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentsSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'Municipality', 'name_ar' => 'البلدية', 'code' => 'municipality'],
            ['name' => 'Electricity', 'name_ar' => 'الكهرباء', 'code' => 'electricity'],
            ['name' => 'Water', 'name_ar' => 'المياه', 'code' => 'water'],
            ['name' => 'Transportation', 'name_ar' => 'النقل والمواصلات', 'code' => 'transportation'],
            ['name' => 'Health', 'name_ar' => 'الصحة', 'code' => 'health'],
        ];

        foreach ($departments as $department) {
            Department::query()->updateOrCreate(
                ['code' => $department['code']],
                [
                    'name' => $department['name'],
                    'name_ar' => $department['name_ar'],
                    'description' => null,
                    'description_ar' => null,
                    'is_active' => true,
                ],
            );
        }
    }
}

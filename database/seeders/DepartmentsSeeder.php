<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentsSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'Municipality', 'code' => 'municipality'],
            ['name' => 'Electricity', 'code' => 'electricity'],
            ['name' => 'Water', 'code' => 'water'],
            ['name' => 'Transportation', 'code' => 'transportation'],
            ['name' => 'Health', 'code' => 'health'],
        ];

        foreach ($departments as $department) {
            Department::query()->updateOrCreate(
                ['code' => $department['code']],
                [
                    'name' => $department['name'],
                    'description' => null,
                    'is_active' => true,
                ],
            );
        }
    }
}

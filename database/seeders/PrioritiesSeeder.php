<?php

namespace Database\Seeders;

use App\Models\Priority;
use Illuminate\Database\Seeder;

class PrioritiesSeeder extends Seeder
{
    public function run(): void
    {
        $priorities = [
            ['name' => 'Low', 'code' => 'low', 'level' => 1, 'color' => '#22c55e'],
            ['name' => 'Medium', 'code' => 'medium', 'level' => 2, 'color' => '#eab308'],
            ['name' => 'High', 'code' => 'high', 'level' => 3, 'color' => '#f97316'],
            ['name' => 'Urgent', 'code' => 'urgent', 'level' => 4, 'color' => '#ef4444'],
        ];

        foreach ($priorities as $priority) {
            Priority::query()->updateOrCreate(
                ['code' => $priority['code']],
                [
                    'name' => $priority['name'],
                    'level' => $priority['level'],
                    'color' => $priority['color'],
                    'description' => null,
                ],
            );
        }
    }
}

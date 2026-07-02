<?php

namespace Database\Seeders;

use App\Models\Priority;
use App\Models\SlaRule;
use Illuminate\Database\Seeder;

class SlaRulesSeeder extends Seeder
{
    public function run(): void
    {
        $hoursByPriority = [
            'low' => ['response' => 48, 'resolution' => 168],
            'medium' => ['response' => 24, 'resolution' => 72],
            'high' => ['response' => 8, 'resolution' => 48],
            'urgent' => ['response' => 2, 'resolution' => 24],
        ];

        foreach ($hoursByPriority as $priorityCode => $hours) {
            $priority = Priority::query()->where('code', $priorityCode)->firstOrFail();

            SlaRule::query()->updateOrCreate(
                [
                    'department_id' => null,
                    'category_id' => null,
                    'priority_id' => $priority->id,
                ],
                [
                    'response_time_hours' => $hours['response'],
                    'resolution_time_hours' => $hours['resolution'],
                    'is_active' => true,
                ],
            );
        }
    }
}

<?php

namespace Database\Factories;

use App\Models\ReportSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportSnapshot>
 */
class ReportSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['complaints_summary', 'sla_performance']),
            'filters' => ['status' => 'submitted'],
            'data' => ['total' => fake()->numberBetween(1, 100)],
            'generated_by' => User::factory()->admin(),
            'generated_at' => now(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\SlaRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlaRule>
 */
class SlaRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'category_id' => ComplaintCategory::factory(),
            'priority_id' => Priority::factory(),
            'response_time_hours' => fake()->numberBetween(4, 48),
            'resolution_time_hours' => fake()->numberBetween(24, 168),
            'is_active' => true,
        ];
    }
}

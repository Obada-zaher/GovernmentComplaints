<?php

namespace Database\Factories;

use App\Models\ComplaintCategory;
use App\Models\ComplaintClassificationRule;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ComplaintClassificationRule>
 */
class ComplaintClassificationRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'category_id' => ComplaintCategory::factory(),
            'keyword' => fake()->unique()->word(),
            'weight' => fake()->numberBetween(1, 10),
            'is_active' => true,
            'language' => 'mixed',
            'normalized_keyword' => null,
            'notes' => null,
        ];
    }
}

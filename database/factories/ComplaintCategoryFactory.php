<?php

namespace Database\Factories;

use App\Models\ComplaintCategory;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ComplaintCategory>
 */
class ComplaintCategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'department_id' => Department::factory(),
            'name' => Str::title($name),
            'code' => Str::slug($name),
            'description' => fake()->optional()->sentence(),
            'keywords' => fake()->words(3),
            'is_active' => true,
        ];
    }
}

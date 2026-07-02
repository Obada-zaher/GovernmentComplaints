<?php

namespace Database\Factories;

use App\Models\Priority;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Priority>
 */
class PriorityFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->words(2, true);
        $code = Str::slug($name).'-'.fake()->unique()->numerify('####');

        return [
            'name' => Str::title($name),
            'code' => $code,
            'level' => fake()->numberBetween(1, 4),
            'color' => fake()->hexColor(),
            'description' => fake()->optional()->sentence(),
        ];
    }
}

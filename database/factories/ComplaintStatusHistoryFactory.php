<?php

namespace Database\Factories;

use App\Models\Complaint;
use App\Models\ComplaintStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ComplaintStatusHistory>
 */
class ComplaintStatusHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'complaint_id' => Complaint::factory(),
            'changed_by' => User::factory(),
            'from_status' => 'submitted',
            'to_status' => fake()->randomElement(['under_review', 'assigned', 'in_progress']),
            'note' => fake()->optional()->sentence(),
            'duration_minutes' => fake()->optional()->numberBetween(10, 240),
        ];
    }
}

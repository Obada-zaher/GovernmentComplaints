<?php

namespace Database\Factories;

use App\Models\Complaint;
use App\Models\ComplaintAssignment;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ComplaintAssignment>
 */
class ComplaintAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'complaint_id' => Complaint::factory(),
            'assigned_by' => User::factory()->admin(),
            'assigned_to' => User::factory()->employee(),
            'department_id' => Department::factory(),
            'note' => fake()->optional()->sentence(),
            'assigned_at' => now(),
        ];
    }
}

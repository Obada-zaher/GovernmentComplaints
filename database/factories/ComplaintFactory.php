<?php

namespace Database\Factories;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Complaint>
 */
class ComplaintFactory extends Factory
{
    public function definition(): array
    {
        return [
            'complaint_number' => 'CMP-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'citizen_id' => User::factory()->citizen(),
            'department_id' => Department::factory(),
            'category_id' => ComplaintCategory::factory(),
            'priority_id' => Priority::factory(),
            'assigned_employee_id' => null,
            'title' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'status' => 'submitted',
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'address' => fake()->address(),
            'source' => fake()->randomElement(['web', 'mobile', 'offline_sync', 'admin']),
            'client_uuid' => fake()->optional()->uuid(),
            'classification_confidence' => fake()->optional()->randomFloat(4, 0, 1),
            'due_at' => now()->addDays(3),
            'first_response_at' => null,
            'resolved_at' => null,
            'closed_at' => null,
            'is_sla_breached' => false,
        ];
    }
}

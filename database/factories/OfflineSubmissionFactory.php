<?php

namespace Database\Factories;

use App\Models\Complaint;
use App\Models\OfflineSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfflineSubmission>
 */
class OfflineSubmissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'citizen_id' => User::factory()->citizen(),
            'client_uuid' => fake()->unique()->uuid(),
            'payload' => [
                'title' => fake()->sentence(4),
                'description' => fake()->paragraph(),
            ],
            'status' => 'pending',
            'synced_complaint_id' => null,
            'error_message' => null,
            'submitted_offline_at' => now()->subHour(),
            'synced_at' => null,
        ];
    }

    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'synced',
            'synced_complaint_id' => Complaint::factory(),
            'synced_at' => now(),
        ]);
    }
}

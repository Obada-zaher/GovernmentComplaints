<?php

namespace Database\Factories;

use App\Models\Complaint;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserNotification>
 */
class UserNotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'complaint_id' => Complaint::factory(),
            'type' => 'complaint_status_changed',
            'title' => fake()->sentence(4),
            'body' => fake()->optional()->paragraph(),
            'data' => ['status' => 'submitted'],
            'read_at' => null,
        ];
    }
}

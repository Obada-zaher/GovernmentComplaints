<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserDeviceToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserDeviceToken>
 */
class UserDeviceTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => 'fake-fcm-token-'.fake()->uuid(),
            'platform' => fake()->randomElement(['web', 'android', 'ios']),
            'device_name' => fake()->optional()->words(2, true),
            'app_version' => fake()->optional()->numerify('#.#.#'),
            'last_used_at' => now(),
            'is_active' => true,
        ];
    }
}

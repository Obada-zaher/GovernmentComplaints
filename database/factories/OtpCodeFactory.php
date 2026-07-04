<?php

namespace Database\Factories;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<OtpCode>
 */
class OtpCodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'phone' => fake()->optional()->numerify('09########'),
            'email' => fake()->optional()->safeEmail(),
            'code_hash' => Hash::make('123456'),
            'purpose' => fake()->randomElement(['register', 'verify_email', 'login']),
            'expires_at' => now()->addMinutes(10),
            'used_at' => null,
            'attempts' => 0,
        ];
    }
}

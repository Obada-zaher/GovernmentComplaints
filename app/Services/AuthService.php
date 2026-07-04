<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(private readonly OtpService $otpService)
    {
    }

    /**
     * @param array{name: string, email: string, phone: string, national_id?: string|null, password: string} $data
     */
    public function register(array $data): User
    {
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'national_id' => $data['national_id'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => 'citizen',
            'is_active' => true,
            'email_verified_at' => null,
            'phone_verified_at' => null,
        ]);

        $this->otpService->createForUser($user, 'register');

        return $user;
    }

    public function findLoginUser(string $login): ?User
    {
        return User::query()
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->first();
    }

    public function issueToken(User $user, ?string $deviceName = null): string
    {
        $user->forceFill(['last_login_at' => now()])->save();

        return $user->createToken($deviceName ?: 'api-token')->plainTextToken;
    }

    public function resetPassword(string $email, string $token, string $password): string
    {
        return Password::reset([
            'email' => $email,
            'token' => $token,
            'password' => $password,
            'password_confirmation' => $password,
        ], function (User $user) use ($password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();
        });
    }
}

<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(private readonly OtpService $otpService)
    {
    }

    /**
     * @param array{name: string, email: string, phone: string, national_id?: string|null, password: string} $data
     * @return array{user: User, otp: string}
     */
    public function register(array $data): array
    {
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'national_id' => $data['national_id'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => 'citizen',
            'is_active' => true,
            'phone_verified_at' => null,
        ]);

        $otp = $this->otpService->createForUser($user, 'register');

        return [
            'user' => $user,
            'otp' => $otp['plain'],
        ];
    }

    public function findLoginUser(string $login): ?User
    {
        return User::query()
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->first();
    }

    public function issueToken(User $user): string
    {
        $user->forceFill(['last_login_at' => now()])->save();

        return $user->createToken('api-token')->plainTextToken;
    }
}

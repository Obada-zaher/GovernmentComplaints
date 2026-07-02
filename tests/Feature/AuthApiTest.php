<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_citizen_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Registration successful. OTP verification required.')
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonMissingPath('data.otp');

        $this->assertDatabaseHas('users', [
            'email' => 'citizen@example.com',
            'phone' => '0999999999',
            'role' => 'citizen',
            'is_active' => true,
        ]);
    }

    public function test_register_ignores_requested_admin_role_and_always_creates_citizen(): void
    {
        $payload = $this->registrationPayload(['role' => 'admin']);

        $this->postJson('/api/v1/auth/register', $payload)->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'citizen@example.com',
            'role' => 'citizen',
        ]);
    }

    public function test_register_generates_otp(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload());
        $userId = $response->json('data.user_id');

        $this->assertDatabaseHas('otp_codes', [
            'user_id' => $userId,
            'purpose' => 'register',
            'used_at' => null,
            'attempts' => 0,
        ]);

        $this->assertNotNull(OtpCode::query()->where('user_id', $userId)->first()?->code_hash);
    }

    public function test_login_with_email_generates_otp(): void
    {
        $user = User::factory()->create([
            'email' => 'citizen@example.com',
            'phone' => '0999999999',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'citizen@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonMissingPath('data.otp');

        $this->assertDatabaseHas('otp_codes', [
            'user_id' => $user->id,
            'purpose' => 'login',
        ]);
    }

    public function test_login_with_phone_generates_otp(): void
    {
        $user = User::factory()->create([
            'email' => 'citizen@example.com',
            'phone' => '0999999999',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => '0999999999',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user_id', $user->id);

        $this->assertDatabaseHas('otp_codes', [
            'user_id' => $user->id,
            'purpose' => 'login',
        ]);
    }

    public function test_wrong_password_returns_unauthorized(): void
    {
        User::factory()->create([
            'email' => 'citizen@example.com',
            'phone' => '0999999999',
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'citizen@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_verify_register_otp_returns_token(): void
    {
        $user = User::factory()->citizen()->create(['phone_verified_at' => null]);
        $this->createOtp($user, 'register', '123456');

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'user_id' => $user->id,
            'otp' => '123456',
            'purpose' => 'register',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.role', 'citizen')
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user']]);

        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_verify_login_otp_returns_token(): void
    {
        $user = User::factory()->citizen()->create();
        $this->createOtp($user, 'login', '123456');

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'user_id' => $user->id,
            'otp' => '123456',
            'purpose' => 'login',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_otp_increments_attempts(): void
    {
        $user = User::factory()->citizen()->create();
        $otp = $this->createOtp($user, 'login', '123456');

        $this->postJson('/api/v1/auth/verify-otp', [
            'user_id' => $user->id,
            'otp' => '000000',
            'purpose' => 'login',
        ])->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertSame(1, $otp->fresh()->attempts);
    }

    public function test_expired_otp_cannot_be_used(): void
    {
        $user = User::factory()->citizen()->create();
        $this->createOtp($user, 'login', '123456', now()->subMinute());

        $this->postJson('/api/v1/auth/verify-otp', [
            'user_id' => $user->id,
            'otp' => '123456',
            'purpose' => 'login',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'OTP has expired.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_auth_me_requires_token(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_auth_me_returns_current_user(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->employee()->create(['department_id' => $department->id]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.department.id', $department->id);
    }

    public function test_logout_deletes_current_token(): void
    {
        $user = User::factory()->citizen()->create();
        $token = $user->createToken('api-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_citizen_cannot_access_admin_ping(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());

        $this->getJson('/api/v1/admin/ping')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_access_admin_ping(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/ping')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_employee_can_access_employee_ping(): void
    {
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->getJson('/api/v1/employee/ping')
            ->assertOk()
            ->assertJsonPath('data.role', 'employee');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Citizen User',
            'email' => 'citizen@example.com',
            'phone' => '0999999999',
            'national_id' => '123456789',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides);
    }

    private function createOtp(User $user, string $purpose, string $plainOtp, mixed $expiresAt = null): OtpCode
    {
        return OtpCode::query()->create([
            'user_id' => $user->id,
            'phone' => $user->phone,
            'email' => $user->email,
            'code_hash' => Hash::make($plainOtp),
            'purpose' => $purpose,
            'expires_at' => $expiresAt ?? now()->addMinutes(10),
            'attempts' => 0,
        ]);
    }
}

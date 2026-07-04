<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\Auth\OtpCodeNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_does_not_return_otp(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Registration successful. A verification code has been sent to your email.')
            ->assertJsonPath('data.requires_otp', true)
            ->assertJsonMissingPath('data.otp')
            ->assertJsonMissingPath('data.token');
    }

    public function test_register_sends_otp_notification(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload());
        $user = User::query()->findOrFail($response->json('data.user_id'));

        Notification::assertSentTo($user, OtpCodeNotification::class);
        $this->assertDatabaseHas('otp_codes', [
            'user_id' => $user->id,
            'purpose' => 'register',
            'used_at' => null,
            'attempts' => 0,
        ]);
        $this->assertNull($user->email_verified_at);
    }

    public function test_register_ignores_requested_admin_role_and_always_creates_citizen(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->registrationPayload(['role' => 'admin']))
            ->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'citizen@example.com',
            'role' => 'citizen',
        ]);
    }

    public function test_login_does_not_return_otp(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'citizen@example.com',
            'phone' => '0999999999',
            'password' => Hash::make('password'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'citizen@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonMissingPath('data.otp')
            ->assertJsonMissingPath('data.token');
    }

    public function test_login_sends_otp_notification(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'citizen@example.com',
            'phone' => '0999999999',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => '0999999999',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('message', 'Login verification code has been sent to your email.');

        Notification::assertSentTo($user, OtpCodeNotification::class);
        $this->assertDatabaseHas('otp_codes', [
            'user_id' => $user->id,
            'purpose' => 'login',
        ]);
    }

    public function test_unverified_login_sends_email_verification_otp(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create([
            'email' => 'citizen@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'citizen@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('message', 'Email verification code has been sent to your email.');

        Notification::assertSentTo($user, OtpCodeNotification::class);
        $this->assertDatabaseHas('otp_codes', [
            'user_id' => $user->id,
            'purpose' => 'verify_email',
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
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid login credentials.');
    }

    public function test_verify_otp_returns_token(): void
    {
        $user = User::factory()->citizen()->unverified()->create();
        $this->createOtp($user, 'register', '123456');

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'user_id' => $user->id,
            'otp' => '123456',
            'purpose' => 'register',
            'device_name' => 'Postman',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Verification successful.')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.role', 'citizen')
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user']]);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseCount('personal_access_tokens', 1);
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

    public function test_otp_cannot_be_reused(): void
    {
        $user = User::factory()->citizen()->create();
        $this->createOtp($user, 'login', '123456');

        $payload = [
            'user_id' => $user->id,
            'otp' => '123456',
            'purpose' => 'login',
        ];

        $this->postJson('/api/v1/auth/verify-otp', $payload)->assertOk();
        $this->postJson('/api/v1/auth/verify-otp', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_expired_otp_fails(): void
    {
        $user = User::factory()->citizen()->create();
        $this->createOtp($user, 'login', '123456', now()->subMinute());

        $this->postJson('/api/v1/auth/verify-otp', [
            'user_id' => $user->id,
            'otp' => '123456',
            'purpose' => 'login',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The verification code is invalid or has expired.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_resend_otp_invalidates_previous_otp(): void
    {
        Notification::fake();
        $user = User::factory()->citizen()->create();
        $oldOtp = $this->createOtp($user, 'login', '123456');

        $this->postJson('/api/v1/auth/resend-otp', [
            'user_id' => $user->id,
            'purpose' => 'login',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('data.otp');

        $this->assertNotNull($oldOtp->fresh()->used_at);
        Notification::assertSentTo($user, OtpCodeNotification::class);
    }

    public function test_forgot_password_returns_generic_message_for_existing_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'citizen@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'citizen@example.com',
        ])->assertOk()
            ->assertJsonPath('message', 'If this email exists, a password reset link has been sent.');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_forgot_password_returns_generic_message_for_non_existing_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'missing@example.com',
        ])->assertOk()
            ->assertJsonPath('message', 'If this email exists, a password reset link has been sent.');

        Notification::assertNothingSent();
    }

    public function test_reset_password_changes_password(): void
    {
        $user = User::factory()->create(['email' => 'citizen@example.com']);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'citizen@example.com',
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_reset_password_revokes_existing_tokens(): void
    {
        $user = User::factory()->create(['email' => 'citizen@example.com']);
        $user->createToken('one');
        $user->createToken('two');
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'citizen@example.com',
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_change_password_requires_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $token = $user->createToken('api-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_change_password_rejects_same_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $token = $user->createToken('api-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'password',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_change_password_revokes_other_tokens(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $currentToken = $user->createToken('current')->plainTextToken;
        $user->createToken('other');

        $this->withHeader('Authorization', 'Bearer '.$currentToken)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->citizen()->create();
        $token = $user->createToken('api-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_all_revokes_all_tokens(): void
    {
        $user = User::factory()->citizen()->create();
        $currentToken = $user->createToken('current')->plainTextToken;
        $user->createToken('other');

        $this->withHeader('Authorization', 'Bearer '.$currentToken)
            ->postJson('/api/v1/auth/logout-all')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_citizen_cannot_access_admin_route(): void
    {
        Sanctum::actingAs(User::factory()->citizen()->create());

        $this->getJson('/api/v1/admin/ping')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_unauthenticated_user_cannot_access_auth_me(): void
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

    public function test_auth_event_is_recorded_for_register(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $this->assertDatabaseHas('auth_events', [
            'user_id' => $response->json('data.user_id'),
            'event' => 'registered',
        ]);
        $this->assertDatabaseHas('auth_events', [
            'user_id' => $response->json('data.user_id'),
            'event' => 'otp_sent',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
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

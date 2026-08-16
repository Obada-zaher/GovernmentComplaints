<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_update_profile(): void
    {
        $this->patchJson('/api/v1/auth/profile', ['name' => 'Updated Citizen'])
            ->assertUnauthorized();
    }

    public function test_authenticated_citizen_can_update_trimmed_name_and_phone(): void
    {
        $user = User::factory()->citizen()->create([
            'name' => 'Original Citizen',
            'phone' => '0990000001',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', [
            'name' => '  Updated Citizen  ',
            'phone' => ' 0990000002 ',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.name', 'Updated Citizen')
            ->assertJsonPath('data.user.phone', '0990000002');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Citizen',
            'phone' => '0990000002',
        ]);
    }

    public function test_profile_update_uses_the_same_user_shape_as_auth_me(): void
    {
        $user = User::factory()->citizen()->create(['phone' => '0990000003']);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/auth/profile', ['name' => 'Authoritative Name'])
            ->assertOk();
        $me = $this->getJson('/api/v1/auth/me')->assertOk();

        $this->assertSame($me->json('data.user'), $response->json('data.user'));
    }

    public function test_profile_update_cannot_target_another_user(): void
    {
        $user = User::factory()->citizen()->create(['name' => 'Current Citizen']);
        $other = User::factory()->citizen()->create(['name' => 'Other Citizen']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', [
            'name' => 'Attempted Update',
            'user_id' => $other->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('fields');

        $this->assertSame('Current Citizen', $user->fresh()->name);
        $this->assertSame('Other Citizen', $other->fresh()->name);
    }

    public function test_profile_update_rejects_role_and_status_changes(): void
    {
        $user = User::factory()->citizen()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', [
            'role' => 'admin',
            'is_active' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('fields');

        $this->assertSame('citizen', $user->fresh()->role);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_profile_update_cannot_change_password(): void
    {
        $user = User::factory()->citizen()->create(['password' => Hash::make('original-password')]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', [
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('fields');

        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_profile_update_rejects_invalid_name_and_phone(): void
    {
        $user = User::factory()->citizen()->create(['phone' => '0990000004']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', ['name' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $this->patchJson('/api/v1/auth/profile', ['phone' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_profile_update_leaves_omitted_fields_unchanged(): void
    {
        $user = User::factory()->citizen()->create([
            'name' => 'Original Name',
            'phone' => '0990000005',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', ['name' => 'Only Name Changed'])
            ->assertOk();

        $this->assertSame('Only Name Changed', $user->fresh()->name);
        $this->assertSame('0990000005', $user->fresh()->phone);
    }

    public function test_profile_update_keeps_email_read_only(): void
    {
        $user = User::factory()->citizen()->create(['email' => 'citizen@example.com']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', ['email' => 'changed@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields');

        $this->assertSame('citizen@example.com', $user->fresh()->email);
    }

    public function test_profile_update_rejects_unknown_internal_fields(): void
    {
        $user = User::factory()->citizen()->create(['national_id' => '12345678901']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', ['national_id' => '99999999999'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields');

        $this->assertSame('12345678901', $user->fresh()->national_id);
    }
}

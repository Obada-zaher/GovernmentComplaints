<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicDeliveryReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok_response(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'System health retrieved successfully.')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.database', 'connected')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['app', 'status', 'environment', 'database', 'queue', 'time', 'version'],
                'meta',
            ]);
    }

    public function test_openapi_files_exist(): void
    {
        $this->assertFileExists(base_path('docs/openapi/gcms-api.openapi.yaml'));
        $this->assertFileExists(base_path('docs/openapi/gcms-api.openapi.json'));
    }

    public function test_postman_collection_and_environment_files_exist(): void
    {
        $collections = [
            'docs/postman/shared.postman_collection.json',
            'docs/postman/mobile.postman_collection.json',
            'docs/postman/web.postman_collection.json',
        ];

        foreach ($collections as $collection) {
            $this->assertFileExists(base_path($collection));
        }

        $this->assertFileExists(base_path('docs/postman/gcms-local.postman_environment.json'));
    }

    public function test_demo_seeder_runs_successfully(): void
    {
        Artisan::call('db:seed', ['--class' => 'DemoDataSeeder']);

        $this->assertDatabaseHas('users', ['email' => 'admin@gcms.test', 'role' => 'admin']);
        $this->assertDatabaseHas('users', ['email' => 'employee@gcms.test', 'role' => 'employee']);
        $this->assertDatabaseHas('users', ['email' => 'citizen@gcms.test', 'role' => 'citizen']);
        $this->assertDatabaseCount('complaints', 9);
    }

    public function test_admin_citizen_and_employee_endpoint_samples_are_protected(): void
    {
        $this->getJson('/api/v1/admin/complaints')->assertUnauthorized();
        $this->getJson('/api/v1/citizen/complaints')->assertUnauthorized();
        $this->getJson('/api/v1/employee/complaints')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->citizen()->create());
        $this->getJson('/api/v1/admin/complaints')->assertForbidden();
        $this->getJson('/api/v1/employee/complaints')->assertForbidden();
    }

    public function test_env_is_ignored_and_user_hides_sensitive_fields(): void
    {
        $gitignore = file_get_contents(base_path('.gitignore'));
        $this->assertStringContainsString('.env', $gitignore);

        $user = User::factory()->create();
        $serialized = $user->toArray();

        $this->assertArrayNotHasKey('password', $serialized);
        $this->assertArrayNotHasKey('remember_token', $serialized);
    }

    public function test_major_list_endpoints_return_pagination_meta(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/complaints')
            ->assertOk()
            ->assertJsonStructure(['meta' => ['current_page', 'per_page', 'total']]);

        $this->getJson('/api/v1/admin/reports/sla-breaches')
            ->assertOk()
            ->assertJsonStructure(['meta' => ['current_page', 'per_page', 'total']]);
    }
}

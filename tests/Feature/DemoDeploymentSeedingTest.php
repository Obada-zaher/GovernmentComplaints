<?php

namespace Tests\Feature;

use Tests\TestCase;

class DemoDeploymentSeedingTest extends TestCase
{
    public function test_render_demo_seeding_is_explicitly_opt_in_after_base_seeding(): void
    {
        $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));
        $environmentExample = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('php artisan db:seed --force', $entrypoint);
        $this->assertStringContainsString('[ "${SEED_DEMO_DATA:-false}" = "true" ]', $entrypoint);
        $this->assertStringContainsString('php artisan db:seed --class=DemoDataSeeder --force', $entrypoint);
        $this->assertStringContainsString('SEED_DEMO_DATA=false', $environmentExample);
        $this->assertStringNotContainsString('migrate:fresh', $entrypoint);
        $this->assertStringNotContainsString('db:wipe', $entrypoint);
    }
}

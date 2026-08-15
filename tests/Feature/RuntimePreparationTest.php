<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RuntimePreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sla_command_is_scheduled_every_minute_with_overlap_protection(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString("->command('complaints:check-sla')", $bootstrap);
        $this->assertStringContainsString('->everyMinute()', $bootstrap);
        $this->assertStringContainsString('->withoutOverlapping(10)', $bootstrap);

        $this->assertSame(0, Artisan::call('schedule:list'));
        $this->assertStringContainsString('complaints:check-sla', Artisan::output());
    }

    public function test_sla_command_can_still_run_manually(): void
    {
        $this->artisan('complaints:check-sla')->assertExitCode(0);
    }

    public function test_process_role_defaults_to_web_and_preserves_web_startup_tasks(): void
    {
        $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));
        $webTasks = $this->functionBody($entrypoint, 'run_web_deployment_tasks');

        $this->assertStringContainsString('process_role="${GCMS_PROCESS_ROLE:-web}"', $entrypoint);
        $this->assertStringContainsString('web)', $entrypoint);
        $this->assertStringContainsString('php artisan migrate --force', $webTasks);
        $this->assertStringContainsString('php artisan db:seed --force', $webTasks);
        $this->assertStringContainsString('php artisan db:seed --class=DemoDataSeeder --force', $webTasks);
        $this->assertStringContainsString('[ "${SEED_DEMO_DATA:-false}" = "true" ]', $webTasks);
    }

    public function test_worker_and_cron_roles_do_not_run_deployment_database_or_cache_commands(): void
    {
        $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

        foreach (['worker', 'cron'] as $role) {
            $roleBlock = $this->roleBlock($entrypoint, $role);

            $this->assertStringContainsString('prepare_runtime_directories', $roleBlock);
            $this->assertStringNotContainsString('migrate', $roleBlock);
            $this->assertStringNotContainsString('db:seed', $roleBlock);
            $this->assertStringNotContainsString('DemoDataSeeder', $roleBlock);
            $this->assertStringNotContainsString('clear', $roleBlock);
        }
    }

    public function test_unknown_process_role_fails_fast(): void
    {
        $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

        $this->assertStringContainsString('Unsupported GCMS_PROCESS_ROLE', $entrypoint);
    }

    public function test_runtime_scripts_use_separate_bounded_worker_and_scheduler_commands(): void
    {
        $worker = file_get_contents(base_path('scripts/render-queue-worker.sh'));
        $scheduler = file_get_contents(base_path('scripts/render-scheduler-run.sh'));

        $this->assertStringContainsString('exec php artisan queue:work --sleep=3 --tries=3 --timeout=60', $worker);
        $this->assertStringNotContainsString('php artisan serve', $worker);
        $this->assertStringNotContainsString('queue:listen', $worker);
        $this->assertStringContainsString('exec php artisan schedule:run', $scheduler);
        $this->assertStringNotContainsString('schedule:work', $scheduler);
    }

    public function test_environment_example_documents_process_roles_and_database_queue(): void
    {
        $environmentExample = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('QUEUE_CONNECTION=database', $environmentExample);
        $this->assertStringContainsString('GCMS_PROCESS_ROLE=web', $environmentExample);
        $this->assertStringContainsString('GCMS_PROCESS_ROLE=worker', $environmentExample);
        $this->assertStringContainsString('GCMS_PROCESS_ROLE=cron', $environmentExample);
    }

    private function functionBody(string $script, string $name): string
    {
        preg_match('/'.$name.'\(\) \{(.*?)^\}/ms', $script, $matches);

        return $matches[1] ?? '';
    }

    private function roleBlock(string $script, string $role): string
    {
        preg_match('/  '.$role.'\)(.*?);;/s', $script, $matches);

        return $matches[1] ?? '';
    }
}

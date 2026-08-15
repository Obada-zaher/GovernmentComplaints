<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintAttachment;
use App\Models\ComplaintStatusHistory;
use App\Models\OfflineSubmission;
use App\Models\User;
use App\Services\ComplaintAttachmentService;
use App\Services\ComplaintService;
use App\Services\Offline\OfflineComplaintSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FinalMicroPatchRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        OfflineSubmission::flushEventListeners();

        parent::tearDown();
    }

    public function test_status_clock_repair_uses_latest_true_transition_and_ignores_same_status_history(): void
    {
        Carbon::setTestNow('2026-08-16 14:00:00');
        $complaint = Complaint::factory()->create([
            'status' => 'in_progress',
            'created_at' => '2026-08-16 08:00:00',
            'updated_at' => '2026-08-16 12:00:00',
            'status_entered_at' => '2026-08-16 08:00:00',
        ]);

        $this->history($complaint, 'submitted', 'under_review', '2026-08-16 09:00:00');
        $this->history($complaint, 'under_review', 'assigned', '2026-08-16 10:00:00');
        $this->history($complaint, 'assigned', 'in_progress', '2026-08-16 11:00:00');
        $this->history($complaint, 'in_progress', 'in_progress', '2026-08-16 12:00:00');

        $this->runStatusClockRepair();

        $this->assertSame('2026-08-16 11:00:00', $complaint->fresh()->status_entered_at?->format('Y-m-d H:i:s'));
    }

    public function test_status_clock_repair_falls_back_to_created_at_when_history_is_missing(): void
    {
        Carbon::setTestNow('2026-08-16 14:00:00');
        $complaint = Complaint::factory()->create([
            'status' => 'in_progress',
            'created_at' => '2026-08-16 08:00:00',
            'updated_at' => '2026-08-16 12:00:00',
            'status_entered_at' => null,
        ]);

        $this->runStatusClockRepair();

        $this->assertSame('2026-08-16 08:00:00', $complaint->fresh()->status_entered_at?->format('Y-m-d H:i:s'));
    }

    public function test_partial_multifile_storage_failure_removes_previous_file_and_rolls_back_attachment_rows(): void
    {
        Storage::fake('public');
        $complaint = Complaint::factory()->create();
        $citizen = User::query()->findOrFail($complaint->citizen_id);
        $failedFile = \Mockery::mock(UploadedFile::class);
        $failedFile->shouldReceive('getClientOriginalExtension')->andReturn('jpg');
        $failedFile->shouldReceive('storeAs')->andReturn(false);

        try {
            DB::transaction(function () use ($complaint, $citizen, $failedFile): void {
                app(ComplaintAttachmentService::class)->storeMany($complaint, $citizen, [
                    UploadedFile::fake()->image('first.jpg')->size(100),
                    $failedFile,
                ]);
            });
            $this->fail('The second storage operation should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to store the complaint attachment.', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('public')->allFiles('complaints/'.$complaint->id));
        $this->assertSame(0, ComplaintAttachment::query()->where('complaint_id', $complaint->id)->count());
    }

    public function test_offline_failure_after_complaint_creation_compensates_attachment_files(): void
    {
        Storage::fake('public');
        $citizen = User::factory()->citizen()->create();
        OfflineSubmission::saving(function (OfflineSubmission $submission): void {
            if ($submission->status === 'synced') {
                throw new RuntimeException('Failure after complaint creation.');
            }
        });

        try {
            app(OfflineComplaintSyncService::class)->sync($citizen, [
                'client_uuid' => 'offline-outer-rollback',
                'title' => 'Offline attachment rollback',
                'description' => 'The outer transaction must compensate stored files.',
                'source' => 'offline_sync',
                'attachments' => [UploadedFile::fake()->image('offline-proof.jpg')->size(100)],
            ]);
            $this->fail('The offline finalization should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Failure after complaint creation.', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('public')->allFiles('complaints'));
        $this->assertSame(0, Complaint::query()->count());
        $this->assertSame(0, ComplaintAttachment::query()->count());
        $this->assertDatabaseHas('offline_submissions', [
            'citizen_id' => $citizen->id,
            'client_uuid' => 'offline-outer-rollback',
            'status' => 'failed',
        ]);
    }

    public function test_recovery_never_regresses_an_already_synced_offline_submission(): void
    {
        $citizen = User::factory()->citizen()->create();
        $complaint = Complaint::factory()->create(['citizen_id' => $citizen->id]);
        $submission = OfflineSubmission::factory()->create([
            'citizen_id' => $citizen->id,
            'client_uuid' => 'preserve-synced-submission',
            'status' => 'synced',
            'synced_complaint_id' => $complaint->id,
            'synced_at' => now(),
        ]);
        $service = new class(app(ComplaintService::class), app(ComplaintAttachmentService::class)) extends OfflineComplaintSyncService
        {
            /** @param array<string, mixed> $data */
            public function recover(User $citizen, array $data, RuntimeException $exception): ?array
            {
                return $this->recoverAfterFailure($citizen, $data, $exception);
            }
        };

        $result = $service->recover($citizen, ['client_uuid' => 'preserve-synced-submission'], new RuntimeException('Later failure'));

        $this->assertTrue($result['idempotent']);
        $this->assertSame($complaint->id, $result['complaint']->id);
        $this->assertSame('synced', $submission->fresh()->status);
        $this->assertSame($complaint->id, $submission->fresh()->synced_complaint_id);
        $this->assertDatabaseHas('complaints', ['id' => $complaint->id]);
    }

    private function history(Complaint $complaint, string $from, string $to, string $at): void
    {
        $history = ComplaintStatusHistory::query()->create([
            'complaint_id' => $complaint->id,
            'from_status' => $from,
            'to_status' => $to,
        ]);
        $history->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
    }

    private function runStatusClockRepair(): void
    {
        $migration = require database_path('migrations/2026_08_16_000004_repair_final_workflow_audit_state.php');
        $migration->up();
    }
}

<?php

namespace App\Services\Offline;

use App\Models\Complaint;
use App\Models\OfflineSubmission;
use App\Models\User;
use App\Services\ComplaintAttachmentService;
use App\Services\ComplaintService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class OfflineComplaintSyncService
{
    public function __construct(
        private readonly ComplaintService $complaintService,
        private readonly ComplaintAttachmentService $attachmentService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{offline_submission: OfflineSubmission, complaint: Complaint, idempotent: bool}
     *
     * @throws Throwable
     */
    public function sync(User $citizen, array $data): array
    {
        $offlineSubmission = OfflineSubmission::query()
            ->with('syncedComplaint')
            ->where('citizen_id', $citizen->id)
            ->where('client_uuid', $data['client_uuid'])
            ->first();

        if ($offlineSubmission && $offlineSubmission->status === 'synced' && $offlineSubmission->syncedComplaint) {
            return $this->result($offlineSubmission, true);
        }

        if (! $offlineSubmission) {
            try {
                $offlineSubmission = OfflineSubmission::query()->create([
                    'citizen_id' => $citizen->id,
                    'client_uuid' => $data['client_uuid'],
                    'payload' => $this->payloadForStorage($data),
                    'status' => 'pending',
                    'submitted_offline_at' => $this->submittedOfflineAt($data),
                ]);
            } catch (UniqueConstraintViolationException) {
                $offlineSubmission = OfflineSubmission::query()
                    ->with('syncedComplaint')
                    ->where('citizen_id', $citizen->id)
                    ->where('client_uuid', $data['client_uuid'])
                    ->firstOrFail();
            }
        }

        $storedFiles = [];

        try {
            return DB::transaction(function () use ($citizen, $data, $offlineSubmission, &$storedFiles): array {
                $offlineSubmission = OfflineSubmission::query()
                    ->where('citizen_id', $citizen->id)
                    ->where('client_uuid', $data['client_uuid'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($offlineSubmission->status === 'synced' && $offlineSubmission->syncedComplaint) {
                    return $this->result($offlineSubmission, true);
                }

                $offlineSubmission->forceFill([
                    'payload' => $this->payloadForStorage($data),
                    'status' => 'pending',
                    'error_message' => null,
                    'submitted_offline_at' => $this->submittedOfflineAt($data),
                ])->save();

                $complaint = $this->complaintService->create($citizen, $this->complaintPayload($data));
                $storedFiles = $complaint->attachments()
                    ->get(['disk', 'file_path'])
                    ->map(fn ($attachment): array => ['disk' => $attachment->disk, 'path' => $attachment->file_path])
                    ->all();

                $offlineSubmission->forceFill([
                    'status' => 'synced',
                    'synced_complaint_id' => $complaint->id,
                    'error_message' => null,
                    'synced_at' => now(),
                ])->save();

                return [
                    'offline_submission' => $offlineSubmission->fresh('syncedComplaint'),
                    'complaint' => $complaint,
                    'idempotent' => false,
                ];
            });
        } catch (Throwable $exception) {
            $this->attachmentService->deleteStoredFiles($storedFiles);

            $recoveredResult = $this->recoverAfterFailure($citizen, $data, $exception);

            if ($recoveredResult) {
                return $recoveredResult;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{offline_submission: OfflineSubmission, complaint: Complaint, idempotent: bool}|null
     */
    protected function recoverAfterFailure(User $citizen, array $data, Throwable $exception): ?array
    {
        return DB::transaction(function () use ($citizen, $data, $exception): ?array {
            $offlineSubmission = OfflineSubmission::query()
                ->where('citizen_id', $citizen->id)
                ->where('client_uuid', $data['client_uuid'])
                ->lockForUpdate()
                ->firstOrFail();

            $offlineSubmission->load('syncedComplaint');

            if ($offlineSubmission->status === 'synced' && $offlineSubmission->syncedComplaint) {
                return $this->result($offlineSubmission, true);
            }

            $offlineSubmission->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();

            return null;
        });
    }

    /**
     * @return array{offline_submission: OfflineSubmission, complaint: Complaint, idempotent: bool}
     */
    private function result(OfflineSubmission $offlineSubmission, bool $idempotent): array
    {
        return [
            'offline_submission' => $offlineSubmission,
            'complaint' => $offlineSubmission->syncedComplaint->load([
                'department',
                'category',
                'priority',
                'assignedEmployee',
                'attachments',
                'statusHistories.changedBy',
            ]),
            'idempotent' => $idempotent,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function complaintPayload(array $data): array
    {
        return array_merge($data, [
            'source' => 'offline_sync',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payloadForStorage(array $data): array
    {
        $payload = $data;
        unset($payload['attachments']);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function submittedOfflineAt(array $data): ?Carbon
    {
        return empty($data['created_offline_at'])
            ? null
            : Carbon::parse($data['created_offline_at']);
    }
}

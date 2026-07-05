<?php

namespace App\Services\Offline;

use App\Models\Complaint;
use App\Models\OfflineSubmission;
use App\Models\User;
use App\Services\ComplaintService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class OfflineComplaintSyncService
{
    public function __construct(private readonly ComplaintService $complaintService) {}

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
                'idempotent' => true,
            ];
        }

        $offlineSubmission ??= new OfflineSubmission([
            'citizen_id' => $citizen->id,
            'client_uuid' => $data['client_uuid'],
        ]);

        $offlineSubmission->forceFill([
            'payload' => $this->payloadForStorage($data),
            'status' => 'pending',
            'error_message' => null,
            'submitted_offline_at' => $this->submittedOfflineAt($data),
        ])->save();

        try {
            $complaint = DB::transaction(function () use ($citizen, $data, $offlineSubmission): Complaint {
                $complaint = $this->complaintService->create($citizen, $this->complaintPayload($data));

                $offlineSubmission->forceFill([
                    'status' => 'synced',
                    'synced_complaint_id' => $complaint->id,
                    'error_message' => null,
                    'synced_at' => now(),
                ])->save();

                return $complaint;
            });
        } catch (Throwable $exception) {
            $offlineSubmission->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        return [
            'offline_submission' => $offlineSubmission->fresh('syncedComplaint'),
            'complaint' => $complaint,
            'idempotent' => false,
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

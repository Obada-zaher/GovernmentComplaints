<?php

namespace App\Services\Complaints;

use App\Models\Complaint;
use App\Models\ComplaintInformationRequest;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComplaintInformationRequestService
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function createPending(Complaint $complaint, User $requestedBy, string $message): ComplaintInformationRequest
    {
        if ($this->activeForUpdate($complaint)) {
            throw ValidationException::withMessages([
                'status' => ['The complaint already has an active information request.'],
            ]);
        }

        return $complaint->informationRequests()->create([
            'requested_by' => $requestedBy->id,
            'message' => $message,
            'status' => 'pending',
            'requested_at' => now(),
        ]);
    }

    public function markCitizenResponse(Complaint $complaint): bool
    {
        $request = $this->activeForUpdate($complaint);

        if (! $request) {
            throw ValidationException::withMessages([
                'complaint' => ['The waiting complaint has no active information request.'],
            ]);
        }

        if ($request->status !== 'pending') {
            return false;
        }

        $request->forceFill([
            'status' => 'responded',
            'responded_at' => now(),
        ])->save();

        return true;
    }

    public function respondWithText(Complaint $complaint, User $citizen, string $message): Complaint
    {
        $respondedComplaint = DB::transaction(function () use ($complaint, $citizen, $message): Complaint {
            $complaint = Complaint::query()->lockForUpdate()->findOrFail($complaint->id);

            if ((int) $complaint->citizen_id !== (int) $citizen->id) {
                throw ValidationException::withMessages([
                    'complaint' => ['You cannot respond to this complaint.'],
                ]);
            }

            if ($complaint->status !== 'waiting_citizen') {
                throw ValidationException::withMessages([
                    'complaint' => ['The complaint is not waiting for additional information.'],
                ]);
            }

            $request = $this->activeForUpdate($complaint);

            if (! $request) {
                throw ValidationException::withMessages([
                    'complaint' => ['The waiting complaint has no active information request.'],
                ]);
            }

            if ($request->response_message !== null) {
                throw ValidationException::withMessages([
                    'message' => ['This information request has already received a text response.'],
                ]);
            }

            $request->forceFill([
                'response_message' => $message,
                'status' => $request->status === 'pending' ? 'responded' : $request->status,
                'responded_at' => $request->responded_at ?? now(),
            ])->save();

            $complaint->statusHistories()->create([
                'changed_by' => $citizen->id,
                'from_status' => 'waiting_citizen',
                'to_status' => 'waiting_citizen',
                'note' => $message,
            ]);

            return $complaint->fresh([
                'department',
                'category',
                'priority',
                'assignedEmployee',
                'attachments.uploadedBy',
                'statusHistories.changedBy',
            ]);
        });

        $this->notificationService->notifyUser(
            $respondedComplaint->assignedEmployee,
            NotificationService::TYPE_COMPLAINT_STATUS_UPDATED,
            $respondedComplaint,
            'Citizen provided additional information',
            "The citizen provided the requested information for complaint {$respondedComplaint->complaint_number}.",
        );

        return $respondedComplaint;
    }

    public function completeAfterCitizenResponse(Complaint $complaint): void
    {
        $request = $this->activeForUpdate($complaint);

        if (! $request || $request->status !== 'responded') {
            throw ValidationException::withMessages([
                'status' => ['The citizen response must be received before continuing this complaint.'],
            ]);
        }

        $request->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();
    }

    private function activeForUpdate(Complaint $complaint): ?ComplaintInformationRequest
    {
        return $complaint->informationRequests()
            ->whereIn('status', ['pending', 'responded'])
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }
}

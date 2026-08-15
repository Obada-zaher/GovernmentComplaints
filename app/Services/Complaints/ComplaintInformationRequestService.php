<?php

namespace App\Services\Complaints;

use App\Models\Complaint;
use App\Models\ComplaintInformationRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ComplaintInformationRequestService
{
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

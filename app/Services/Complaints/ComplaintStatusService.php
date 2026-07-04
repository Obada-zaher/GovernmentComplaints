<?php

namespace App\Services\Complaints;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComplaintStatusService
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $allowedTransitions = [
        'submitted' => ['under_review', 'rejected'],
        'under_review' => ['assigned', 'rejected', 'escalated'],
        'assigned' => ['in_progress', 'escalated'],
        'in_progress' => ['waiting_citizen', 'resolved', 'escalated'],
        'waiting_citizen' => ['in_progress', 'resolved'],
        'resolved' => ['closed'],
        'closed' => [],
        'rejected' => [],
        'escalated' => ['assigned', 'in_progress', 'resolved'],
    ];

    public function updateStatus(Complaint $complaint, User $changedBy, string $toStatus, ?string $note = null, bool $allowAssignmentShortcut = false): Complaint
    {
        return DB::transaction(function () use ($complaint, $changedBy, $toStatus, $note, $allowAssignmentShortcut): Complaint {
            $complaint->refresh();
            $fromStatus = $complaint->status;

            if ($fromStatus === $toStatus) {
                throw ValidationException::withMessages([
                    'status' => ['The complaint is already in the requested status.'],
                ]);
            }

            if (! $this->canTransition($fromStatus, $toStatus, $allowAssignmentShortcut)) {
                throw ValidationException::withMessages([
                    'status' => ["Invalid status transition from {$fromStatus} to {$toStatus}."],
                ]);
            }

            $complaint->forceFill([
                'status' => $toStatus,
                'first_response_at' => $complaint->first_response_at ?? now(),
                'resolved_at' => $toStatus === 'resolved' && ! $complaint->resolved_at ? now() : $complaint->resolved_at,
                'closed_at' => $toStatus === 'closed' && ! $complaint->closed_at ? now() : $complaint->closed_at,
            ])->save();

            $this->createHistory($complaint, $changedBy, $fromStatus, $toStatus, $note);

            return $complaint->fresh();
        });
    }

    public function addTimelineNote(Complaint $complaint, User $changedBy, ?string $note = null): void
    {
        if (! $complaint->first_response_at) {
            $complaint->forceFill(['first_response_at' => now()])->save();
        }

        $this->createHistory($complaint, $changedBy, $complaint->status, $complaint->status, $note);
    }

    private function canTransition(string $fromStatus, string $toStatus, bool $allowAssignmentShortcut): bool
    {
        if ($allowAssignmentShortcut && $fromStatus === 'submitted' && $toStatus === 'assigned') {
            return true;
        }

        return in_array($toStatus, $this->allowedTransitions[$fromStatus] ?? [], true);
    }

    private function createHistory(Complaint $complaint, User $changedBy, string $fromStatus, string $toStatus, ?string $note): void
    {
        $lastHistory = $complaint->statusHistories()->latest()->first();
        $startedAt = $lastHistory?->created_at ?? $complaint->created_at ?? now();
        $durationMinutes = max(0, (int) $startedAt->diffInMinutes(now()));

        $complaint->statusHistories()->create([
            'changed_by' => $changedBy->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'duration_minutes' => $durationMinutes,
        ]);
    }
}

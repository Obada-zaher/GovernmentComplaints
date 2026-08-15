<?php

namespace App\Services\Complaints;

use App\Models\Complaint;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Sla\SlaPauseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComplaintStatusService
{
    public function __construct(
        private readonly ComplaintInformationRequestService $informationRequestService,
        private readonly SlaPauseService $slaPauseService,
        private readonly NotificationService $notificationService,
    ) {}

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

    public function updateStatus(Complaint $complaint, User $changedBy, string $toStatus, ?string $note = null): Complaint
    {
        $slaBreachedBeforePause = false;
        $updatedComplaint = DB::transaction(function () use ($complaint, $changedBy, $toStatus, $note, &$slaBreachedBeforePause): Complaint {
            $complaint = Complaint::query()->lockForUpdate()->findOrFail($complaint->id);
            $fromStatus = $complaint->status;
            $statusEnteredAt = $complaint->status_entered_at ?? $complaint->created_at ?? now();
            $transitionedAt = now();

            if ($fromStatus === $toStatus) {
                throw ValidationException::withMessages([
                    'status' => ['The complaint is already in the requested status.'],
                ]);
            }

            if (! $this->canTransition($fromStatus, $toStatus)) {
                throw ValidationException::withMessages([
                    'status' => ["Invalid status transition from {$fromStatus} to {$toStatus}."],
                ]);
            }

            $this->ensureAssigneeForStatus($complaint, $toStatus);

            if ($toStatus === 'waiting_citizen') {
                $message = trim((string) $note);

                if ($message === '') {
                    throw ValidationException::withMessages([
                        'note' => ['A note is required when requesting additional information from the citizen.'],
                    ]);
                }

                $this->informationRequestService->createPending($complaint, $changedBy, $message);
                $slaBreachedBeforePause = $this->slaPauseService->pause($complaint);
            }

            if ($fromStatus === 'waiting_citizen' && in_array($toStatus, ['in_progress', 'resolved'], true)) {
                $this->informationRequestService->completeAfterCitizenResponse($complaint);
                $this->slaPauseService->resume($complaint, $toStatus === 'in_progress');
            }

            $complaint->forceFill([
                'status' => $toStatus,
                'status_entered_at' => $transitionedAt,
                'first_response_at' => $complaint->first_response_at ?? now(),
                'resolved_at' => $toStatus === 'resolved' && ! $complaint->resolved_at ? now() : $complaint->resolved_at,
                'closed_at' => $toStatus === 'closed' && ! $complaint->closed_at ? now() : $complaint->closed_at,
            ])->save();

            $this->createHistory($complaint, $changedBy, $fromStatus, $toStatus, $note, $statusEnteredAt, $transitionedAt);

            return $complaint->fresh();
        });

        if ($slaBreachedBeforePause) {
            $this->notifySlaBreach($updatedComplaint);
        }

        return $updatedComplaint;
    }

    public function addTimelineNote(Complaint $complaint, User $changedBy, ?string $note = null): void
    {
        DB::transaction(function () use ($complaint, $changedBy, $note): void {
            $complaint = Complaint::query()->lockForUpdate()->findOrFail($complaint->id);

            $this->createHistory($complaint, $changedBy, $complaint->status, $complaint->status, $note);
        });
    }

    private function canTransition(string $fromStatus, string $toStatus): bool
    {
        return in_array($toStatus, $this->allowedTransitions[$fromStatus] ?? [], true);
    }

    private function ensureAssigneeForStatus(Complaint $complaint, string $toStatus): void
    {
        if (! in_array($toStatus, ['assigned', 'in_progress', 'waiting_citizen'], true)) {
            return;
        }

        $hasValidAssignee = $complaint->assigned_employee_id
            && User::query()
                ->whereKey($complaint->assigned_employee_id)
                ->where('role', 'employee')
                ->where('is_active', true)
                ->whereNotNull('department_id')
                ->where('department_id', $complaint->department_id)
                ->exists();

        if (! $hasValidAssignee) {
            throw ValidationException::withMessages([
                'assigned_employee_id' => ["A complaint in {$toStatus} status must have a valid assigned employee."],
            ]);
        }
    }

    private function createHistory(Complaint $complaint, User $changedBy, string $fromStatus, string $toStatus, ?string $note, mixed $statusEnteredAt = null, mixed $transitionedAt = null): void
    {
        $durationMinutes = 0;

        if ($fromStatus !== $toStatus) {
            $startedAt = $statusEnteredAt ?? $complaint->status_entered_at ?? $complaint->created_at ?? now();
            $durationMinutes = max(0, (int) $startedAt->diffInMinutes($transitionedAt ?? now()));
        }

        $complaint->statusHistories()->create([
            'changed_by' => $changedBy->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'duration_minutes' => $durationMinutes,
        ]);
    }

    private function notifySlaBreach(Complaint $complaint): void
    {
        if ($complaint->assignedEmployee) {
            $this->notificationService->notifyUser(
                $complaint->assignedEmployee,
                NotificationService::TYPE_SLA_BREACHED,
                $complaint,
                'SLA breached for assigned complaint',
                "Complaint {$complaint->complaint_number} has breached its SLA deadline.",
                once: true,
            );
        }

        $this->notificationService->notifyAdmins(
            NotificationService::TYPE_SLA_BREACHED,
            $complaint,
            'Complaint SLA breached',
            "Complaint {$complaint->complaint_number} has breached its SLA deadline.",
            once: true,
        );
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Complaint;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckComplaintSlaBreaches extends Command
{
    protected $signature = 'complaints:check-sla';

    protected $description = 'Mark overdue unresolved complaints as SLA breached and notify responsible users.';

    /**
     * @var array<int, string>
     */
    private array $terminalStatuses = ['resolved', 'closed', 'rejected'];

    /**
     * @var array<int, string>
     */
    private array $escalatableStatuses = ['under_review', 'assigned', 'in_progress'];

    public function __construct(private readonly NotificationService $notificationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = Complaint::query()
            ->with(['assignedEmployee'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->where('is_sla_breached', false)
            ->whereNotIn('status', $this->terminalStatuses);

        $checkedCount = (clone $query)->count();
        $breachedCount = 0;
        $notifiedUserIds = [];

        $query->orderBy('id')->chunkById(100, function ($complaints) use (&$breachedCount, &$notifiedUserIds): void {
            foreach ($complaints as $complaint) {
                $complaint = DB::transaction(function () use ($complaint): Complaint {
                    $complaint->refresh();

                    if ($complaint->is_sla_breached || in_array($complaint->status, $this->terminalStatuses, true)) {
                        return $complaint;
                    }

                    $fromStatus = $complaint->status;
                    $toStatus = in_array($fromStatus, $this->escalatableStatuses, true)
                        ? 'escalated'
                        : $fromStatus;

                    $complaint->forceFill([
                        'is_sla_breached' => true,
                        'status' => $toStatus,
                    ])->save();

                    $this->createSystemHistory($complaint, $fromStatus, $toStatus);

                    return $complaint->fresh(['assignedEmployee']);
                });

                if (! $complaint->is_sla_breached) {
                    continue;
                }

                $breachedCount++;

                $notifications = collect();

                if ($complaint->assignedEmployee) {
                    $employeeNotification = $this->notificationService->notifyUser(
                        $complaint->assignedEmployee,
                        NotificationService::TYPE_SLA_BREACHED,
                        $complaint,
                        'SLA breached for assigned complaint',
                        "Complaint {$complaint->complaint_number} has breached its SLA deadline.",
                        once: true,
                    );

                    if ($employeeNotification) {
                        $notifications->push($employeeNotification);
                    }
                }

                $notifications = $notifications->merge($this->notificationService->notifyAdmins(
                    NotificationService::TYPE_SLA_BREACHED,
                    $complaint,
                    'Complaint SLA breached',
                    "Complaint {$complaint->complaint_number} has breached its SLA deadline.",
                    once: true,
                ));

                foreach ($notifications as $notification) {
                    $notifiedUserIds[$notification->user_id] = true;
                }
            }
        });

        $notifiedCount = count($notifiedUserIds);

        $this->info("Checked complaints: {$checkedCount}");
        $this->info("Breached complaints: {$breachedCount}");
        $this->info("Notified users: {$notifiedCount}");

        return self::SUCCESS;
    }

    private function createSystemHistory(Complaint $complaint, string $fromStatus, string $toStatus): void
    {
        $lastHistory = $complaint->statusHistories()->latest()->first();
        $startedAt = $lastHistory?->created_at ?? $complaint->created_at ?? now();
        $durationMinutes = max(0, (int) $startedAt->diffInMinutes(now()));

        $complaint->statusHistories()->create([
            'changed_by' => null,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => 'SLA breached automatically by system.',
            'duration_minutes' => $durationMinutes,
        ]);
    }
}

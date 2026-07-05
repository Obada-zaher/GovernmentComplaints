<?php

namespace App\Services\Notifications;

use App\Models\Complaint;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Collection;

class NotificationService
{
    public const TYPE_COMPLAINT_CREATED = 'complaint_created';

    public const TYPE_COMPLAINT_ASSIGNED = 'complaint_assigned';

    public const TYPE_COMPLAINT_STATUS_UPDATED = 'complaint_status_updated';

    public const TYPE_SLA_BREACHED = 'sla_breached';

    public const TYPE_COMPLAINT_RESOLVED = 'complaint_resolved';

    public const TYPE_COMPLAINT_CLOSED = 'complaint_closed';

    public function __construct(private readonly NotificationDispatcherService $dispatcher) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function notifyUser(
        ?User $user,
        string $type,
        ?Complaint $complaint,
        string $title,
        ?string $body = null,
        array $data = [],
        bool $once = false,
    ): ?UserNotification {
        if (! $user) {
            return null;
        }

        return $this->dispatcher->dispatch($user, $type, $complaint, $title, $body, $data, $once);
    }

    /**
     * @param  iterable<int, User>  $users
     * @param  array<string, mixed>  $data
     * @return Collection<int, UserNotification>
     */
    public function notifyUsers(
        iterable $users,
        string $type,
        ?Complaint $complaint,
        string $title,
        ?string $body = null,
        array $data = [],
        bool $once = false,
    ): Collection {
        $notifications = collect();
        $seenUserIds = [];

        foreach ($users as $user) {
            if (! $user || isset($seenUserIds[$user->id])) {
                continue;
            }

            $seenUserIds[$user->id] = true;
            $notification = $this->notifyUser($user, $type, $complaint, $title, $body, $data, $once);

            if ($notification) {
                $notifications->push($notification);
            }
        }

        return $notifications;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, UserNotification>
     */
    public function notifyAdmins(
        string $type,
        ?Complaint $complaint,
        string $title,
        ?string $body = null,
        array $data = [],
        bool $once = false,
    ): Collection {
        $admins = User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get();

        return $this->notifyUsers($admins, $type, $complaint, $title, $body, $data, $once);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, UserNotification>
     */
    public function notifyDepartmentEmployees(
        ?int $departmentId,
        string $type,
        ?Complaint $complaint,
        string $title,
        ?string $body = null,
        array $data = [],
        bool $once = false,
    ): Collection {
        if (! $departmentId) {
            return collect();
        }

        $employees = User::query()
            ->where('role', 'employee')
            ->where('is_active', true)
            ->where('department_id', $departmentId)
            ->get();

        return $this->notifyUsers($employees, $type, $complaint, $title, $body, $data, $once);
    }
}

<?php

namespace App\Jobs\Notifications;

use App\Models\Complaint;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Complaints\ComplaintEventNotification;
use App\Services\Notifications\NotificationDeliveryLogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendComplaintEmailNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
        public readonly string $type,
        public readonly ?int $complaintId,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?int $userNotificationId = null,
    ) {}

    public function handle(NotificationDeliveryLogService $deliveryLogs): void
    {
        $user = User::query()->find($this->userId);

        if (! $user) {
            return;
        }

        $complaint = $this->complaintId ? Complaint::query()->find($this->complaintId) : null;
        $userNotification = $this->userNotificationId ? UserNotification::query()->find($this->userNotificationId) : null;
        $payload = [
            'title' => $this->title,
            'body' => $this->body,
            'type' => $this->type,
            'complaint_id' => $complaint?->id,
            'complaint_number' => $complaint?->complaint_number,
            'status' => $complaint?->status,
        ];

        try {
            Notification::send($user, new ComplaintEventNotification($complaint, $this->type, $this->title, $this->body));
            $deliveryLogs->record($user, $userNotification, $complaint, 'email', $this->type, 'sent', $user->email, 'mailtrap', $payload);
        } catch (Throwable $exception) {
            $deliveryLogs->record($user, $userNotification, $complaint, 'email', $this->type, 'failed', $user->email, 'mailtrap', $payload, $exception->getMessage());
        }
    }
}

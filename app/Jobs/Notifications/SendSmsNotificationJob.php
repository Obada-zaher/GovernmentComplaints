<?php

namespace App\Jobs\Notifications;

use App\Models\Complaint;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\Channels\SmsNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendSmsNotificationJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $type,
        public readonly ?int $complaintId,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly array $data = [],
        public readonly ?int $userNotificationId = null,
    ) {}

    public function handle(SmsNotificationService $sms): void
    {
        $user = User::query()->find($this->userId);

        if (! $user) {
            return;
        }

        $sms->send(
            $user,
            $this->type,
            $this->complaintId ? Complaint::query()->find($this->complaintId) : null,
            $this->title,
            $this->body,
            $this->data,
            $this->userNotificationId ? UserNotification::query()->find($this->userNotificationId) : null,
        );
    }
}

<?php

namespace App\Services\Notifications;

use App\Models\Complaint;
use App\Models\NotificationDeliveryLog;
use App\Models\User;
use App\Models\UserNotification;

class NotificationDeliveryLogService
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function record(
        ?User $user,
        ?UserNotification $userNotification,
        ?Complaint $complaint,
        string $channel,
        string $type,
        string $status,
        ?string $recipient = null,
        ?string $provider = null,
        ?array $payload = null,
        ?string $errorMessage = null,
        ?string $providerMessageId = null,
    ): NotificationDeliveryLog {
        return NotificationDeliveryLog::query()->create([
            'user_id' => $user?->id,
            'user_notification_id' => $userNotification?->id,
            'complaint_id' => $complaint?->id,
            'channel' => $channel,
            'type' => $type,
            'recipient' => $recipient,
            'status' => $status,
            'provider' => $provider,
            'provider_message_id' => $providerMessageId,
            'error_message' => $errorMessage,
            'payload' => $payload,
            'sent_at' => $status === 'sent' ? now() : null,
            'failed_at' => $status === 'failed' ? now() : null,
        ]);
    }
}

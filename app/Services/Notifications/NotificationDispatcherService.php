<?php

namespace App\Services\Notifications;

use App\Jobs\Notifications\SendComplaintEmailNotificationJob;
use App\Jobs\Notifications\SendPushNotificationJob;
use App\Jobs\Notifications\SendSmsNotificationJob;
use App\Models\Complaint;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\UserNotification;

class NotificationDispatcherService
{
    /**
     * @var array<int, string>
     */
    private array $emailTypes = [
        NotificationService::TYPE_COMPLAINT_ASSIGNED,
        NotificationService::TYPE_SLA_BREACHED,
        NotificationService::TYPE_COMPLAINT_RESOLVED,
    ];

    public function __construct(private readonly NotificationDeliveryLogService $deliveryLogs) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function dispatch(
        User $user,
        string $type,
        ?Complaint $complaint,
        string $title,
        ?string $body = null,
        array $data = [],
        bool $once = false,
    ): ?UserNotification {
        $preferences = $this->preferencesFor($user);
        $payload = array_merge($this->complaintData($complaint), $data);

        $notification = $this->createDatabaseNotification($user, $type, $complaint, $title, $body, $payload, $once, $preferences);

        if ($once && $notification && ! $notification->wasRecentlyCreated) {
            return $notification;
        }

        if (! $this->eventEnabled($preferences, $type)) {
            $this->logSkippedNonDatabaseChannels($user, $notification, $complaint, $type, $payload, 'Notification event is disabled by user preferences.');

            return $notification;
        }

        $this->dispatchEmail($user, $type, $complaint, $title, $body, $payload, $notification, $preferences);
        $this->dispatchPush($user, $type, $complaint, $title, $body, $payload, $notification, $preferences);
        $this->dispatchSms($user, $type, $complaint, $title, $body, $payload, $notification, $preferences);

        return $notification;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createDatabaseNotification(
        User $user,
        string $type,
        ?Complaint $complaint,
        string $title,
        ?string $body,
        array $payload,
        bool $once,
        NotificationPreference $preferences,
    ): ?UserNotification {
        if (! $preferences->database_enabled) {
            $this->deliveryLogs->record($user, null, $complaint, 'database', $type, 'skipped', null, 'database', $payload, 'Database notifications are disabled.');

            return null;
        }

        if ($once && $complaint) {
            $notification = UserNotification::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'complaint_id' => $complaint->id,
                    'type' => $type,
                ],
                [
                    'title' => $title,
                    'body' => $body,
                    'data' => $payload,
                ],
            );
        } else {
            $notification = UserNotification::query()->create([
                'user_id' => $user->id,
                'complaint_id' => $complaint?->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $payload,
            ]);
        }

        if ($notification->wasRecentlyCreated) {
            $this->deliveryLogs->record($user, $notification, $complaint, 'database', $type, 'sent', null, 'database', $payload);
        }

        return $notification;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchEmail(
        User $user,
        string $type,
        ?Complaint $complaint,
        string $title,
        ?string $body,
        array $payload,
        ?UserNotification $notification,
        NotificationPreference $preferences,
    ): void {
        if (! in_array($type, $this->emailTypes, true)) {
            $this->deliveryLogs->record($user, $notification, $complaint, 'email', $type, 'skipped', $user->email, 'mailtrap', $payload, 'Email is not enabled for this event type.');

            return;
        }

        if (! $preferences->email_enabled) {
            $this->deliveryLogs->record($user, $notification, $complaint, 'email', $type, 'skipped', $user->email, 'mailtrap', $payload, 'Email notifications are disabled by user preferences.');

            return;
        }

        SendComplaintEmailNotificationJob::dispatch($user->id, $type, $complaint?->id, $title, $body, $notification?->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchPush(
        User $user,
        string $type,
        ?Complaint $complaint,
        string $title,
        ?string $body,
        array $payload,
        ?UserNotification $notification,
        NotificationPreference $preferences,
    ): void {
        if (! $preferences->push_enabled) {
            $this->deliveryLogs->record($user, $notification, $complaint, 'push', $type, 'skipped', null, 'fcm', $payload, 'Push notifications are disabled by user preferences.');

            return;
        }

        SendPushNotificationJob::dispatch($user->id, $type, $complaint?->id, $title, $body, $payload, $notification?->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchSms(
        User $user,
        string $type,
        ?Complaint $complaint,
        string $title,
        ?string $body,
        array $payload,
        ?UserNotification $notification,
        NotificationPreference $preferences,
    ): void {
        if (! $preferences->sms_enabled) {
            $this->deliveryLogs->record($user, $notification, $complaint, 'sms', $type, 'skipped', $user->phone, (string) config('gcms_notifications.sms.provider', 'log'), $payload, 'SMS notifications are disabled by user preferences.');

            return;
        }

        SendSmsNotificationJob::dispatch($user->id, $type, $complaint?->id, $title, $body, $payload, $notification?->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logSkippedNonDatabaseChannels(
        User $user,
        ?UserNotification $notification,
        ?Complaint $complaint,
        string $type,
        array $payload,
        string $reason,
    ): void {
        $this->deliveryLogs->record($user, $notification, $complaint, 'email', $type, 'skipped', $user->email, 'mailtrap', $payload, $reason);
        $this->deliveryLogs->record($user, $notification, $complaint, 'push', $type, 'skipped', null, 'fcm', $payload, $reason);
        $this->deliveryLogs->record($user, $notification, $complaint, 'sms', $type, 'skipped', $user->phone, (string) config('gcms_notifications.sms.provider', 'log'), $payload, $reason);
    }

    private function preferencesFor(User $user): NotificationPreference
    {
        return NotificationPreference::query()->firstOrCreate(
            ['user_id' => $user->id],
            NotificationPreference::defaults(),
        );
    }

    private function eventEnabled(NotificationPreference $preferences, string $type): bool
    {
        return (bool) ($preferences->{$type} ?? true);
    }

    /**
     * @return array<string, mixed>
     */
    private function complaintData(?Complaint $complaint): array
    {
        if (! $complaint) {
            return [];
        }

        return [
            'complaint_id' => $complaint->id,
            'complaint_number' => $complaint->complaint_number,
            'status' => $complaint->status,
            'url_hint' => "/complaints/{$complaint->id}",
        ];
    }
}

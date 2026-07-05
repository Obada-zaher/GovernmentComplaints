<?php

namespace App\Services\Notifications\Channels;

use App\Models\Complaint;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationDeliveryLogService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PushNotificationService
{
    public function __construct(private readonly NotificationDeliveryLogService $deliveryLogs) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function send(
        User $user,
        string $type,
        ?Complaint $complaint,
        string $title,
        ?string $body = null,
        array $data = [],
        ?UserNotification $userNotification = null,
    ): void {
        $payload = $this->payload($type, $complaint, $title, $body, $data);

        if (! (bool) config('gcms_notifications.push.enabled', false)) {
            $this->deliveryLogs->record($user, $userNotification, $complaint, 'push', $type, 'skipped', null, 'fcm', $payload, 'Push notifications are disabled.');

            return;
        }

        $tokens = $user->deviceTokens()
            ->where('is_active', true)
            ->latest('last_used_at')
            ->get();

        if ($tokens->isEmpty()) {
            $this->deliveryLogs->record($user, $userNotification, $complaint, 'push', $type, 'skipped', null, 'fcm', $payload, 'User has no active device tokens.');

            return;
        }

        foreach ($tokens as $deviceToken) {
            try {
                $providerMessageId = $this->sendToFcm($deviceToken->token, $payload);

                $this->deliveryLogs->record(
                    $user,
                    $userNotification,
                    $complaint,
                    'push',
                    $type,
                    'sent',
                    'device_token:'.$deviceToken->id,
                    $providerMessageId ? 'fcm' : 'fcm-log',
                    $payload,
                    providerMessageId: $providerMessageId,
                );
            } catch (Throwable $exception) {
                $this->deliveryLogs->record(
                    $user,
                    $userNotification,
                    $complaint,
                    'push',
                    $type,
                    'failed',
                    'device_token:'.$deviceToken->id,
                    'fcm',
                    $payload,
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendToFcm(string $token, array $payload): ?string
    {
        $serverKey = config('gcms_notifications.push.fcm.server_key');

        if (! $serverKey) {
            Log::info('FCM push notification simulated locally.', [
                'token_hash' => sha1($token),
                'payload' => $payload,
            ]);

            return null;
        }

        $response = Http::timeout(8)
            ->withHeaders([
                'Authorization' => 'key='.$serverKey,
                'Content-Type' => 'application/json',
            ])
            ->post('https://fcm.googleapis.com/fcm/send', [
                'to' => $token,
                'notification' => [
                    'title' => $payload['title'],
                    'body' => $payload['body'],
                ],
                'data' => $payload,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('FCM request failed with status '.$response->status().'.');
        }

        return (string) data_get($response->json(), 'message_id');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(string $type, ?Complaint $complaint, string $title, ?string $body, array $data): array
    {
        return array_filter([
            'title' => $title,
            'body' => $body ?? $title,
            'type' => $type,
            'complaint_id' => $complaint?->id,
            'complaint_number' => $complaint?->complaint_number,
            'status' => $complaint?->status,
            'click_action' => $complaint ? 'OPEN_COMPLAINT' : null,
            'url_hint' => $complaint ? "/complaints/{$complaint->id}" : null,
        ] + $data, fn ($value): bool => $value !== null);
    }
}

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
    public function __construct(
        private readonly NotificationDeliveryLogService $deliveryLogs,
        private readonly PushTokenProviderDetector $providerDetector,
    ) {}

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
            $provider = $this->providerDetector->detect($deviceToken->token);

            try {
                $providerMessageId = $provider === PushTokenProviderDetector::EXPO
                    ? $this->sendToExpo($deviceToken->token, $payload)
                    : $this->sendToFcm($deviceToken->token, $payload);

                $this->deliveryLogs->record(
                    $user,
                    $userNotification,
                    $complaint,
                    'push',
                    $type,
                    'sent',
                    'device_token:'.$deviceToken->id,
                    $provider === PushTokenProviderDetector::EXPO
                        ? PushTokenProviderDetector::EXPO
                        : ($providerMessageId ? 'fcm' : 'fcm-log'),
                    $payload,
                    providerMessageId: $providerMessageId,
                );
            } catch (Throwable $exception) {
                if ($exception instanceof ExpoPushDeliveryException && $exception->deviceNotRegistered) {
                    $deviceToken->forceFill(['is_active' => false])->save();
                }

                $this->deliveryLogs->record(
                    $user,
                    $userNotification,
                    $complaint,
                    'push',
                    $type,
                    'failed',
                    'device_token:'.$deviceToken->id,
                    $provider,
                    $payload,
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendToExpo(string $token, array $payload): string
    {
        $request = Http::timeout(8)->acceptJson();
        $accessToken = config('gcms_notifications.push.expo.access_token');

        if ($accessToken) {
            $request = $request->withToken($accessToken);
        }

        $response = $request->post((string) config('gcms_notifications.push.expo.url'), [
            'to' => $token,
            'title' => $payload['title'],
            'body' => $payload['body'],
            'sound' => 'default',
            'data' => $payload,
        ]);

        if ($response->failed()) {
            throw new ExpoPushDeliveryException('Expo push request failed with status '.$response->status().'.');
        }

        $ticket = $response->json('data');
        if (is_array($ticket) && array_is_list($ticket)) {
            $ticket = $ticket[0] ?? null;
        }

        if (! is_array($ticket)) {
            throw new ExpoPushDeliveryException('Expo push response did not include a push ticket.');
        }

        if (($ticket['status'] ?? null) !== 'ok') {
            $detail = data_get($ticket, 'details.error');
            $message = $ticket['message'] ?? 'Expo push ticket reported an error.';
            $errorMessage = $detail ? $message.' ('.$detail.').' : $message;

            throw new ExpoPushDeliveryException($errorMessage, $detail === 'DeviceNotRegistered');
        }

        $ticketId = $ticket['id'] ?? null;
        if (! is_string($ticketId) || $ticketId === '') {
            throw new ExpoPushDeliveryException('Expo push ticket did not include an ID.');
        }

        return $ticketId;
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

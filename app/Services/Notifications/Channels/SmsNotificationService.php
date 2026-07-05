<?php

namespace App\Services\Notifications\Channels;

use App\Models\Complaint;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationDeliveryLogService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmsNotificationService
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
        $message = $this->message($complaint, $title, $body);
        $payload = [
            'message' => $message,
            'type' => $type,
            'complaint_id' => $complaint?->id,
            'complaint_number' => $complaint?->complaint_number,
            'status' => $complaint?->status,
        ] + $data;

        if (! (bool) config('gcms_notifications.sms.enabled', false)) {
            $this->deliveryLogs->record($user, $userNotification, $complaint, 'sms', $type, 'skipped', $user->phone, $this->provider(), $payload, 'SMS notifications are disabled.');

            return;
        }

        if (! $user->phone) {
            $this->deliveryLogs->record($user, $userNotification, $complaint, 'sms', $type, 'skipped', null, $this->provider(), $payload, 'User has no phone number.');

            return;
        }

        try {
            $providerMessageId = match ($this->provider()) {
                'log' => $this->sendToLog($user->phone, $message),
                'twilio' => $this->sendToTwilio($user->phone, $message),
                default => throw new \RuntimeException('Unsupported SMS provider: '.$this->provider()),
            };

            $this->deliveryLogs->record($user, $userNotification, $complaint, 'sms', $type, 'sent', $user->phone, $this->provider(), $payload, providerMessageId: $providerMessageId);
        } catch (Throwable $exception) {
            $this->deliveryLogs->record($user, $userNotification, $complaint, 'sms', $type, 'failed', $user->phone, $this->provider(), $payload, $exception->getMessage());
        }
    }

    private function provider(): string
    {
        return (string) config('gcms_notifications.sms.provider', 'log');
    }

    private function sendToLog(string $phone, string $message): string
    {
        Log::info('SMS notification simulated locally.', [
            'to' => $phone,
            'message' => $message,
        ]);

        return 'log-'.sha1($phone.$message.now()->timestamp);
    }

    private function sendToTwilio(string $phone, string $message): string
    {
        $sid = config('gcms_notifications.sms.twilio.sid');
        $token = config('gcms_notifications.sms.twilio.token');
        $from = config('gcms_notifications.sms.twilio.from');

        if (! $sid || ! $token || ! $from) {
            throw new \RuntimeException('Twilio credentials are not configured.');
        }

        $response = Http::timeout(8)
            ->asForm()
            ->withBasicAuth((string) $sid, (string) $token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'From' => $from,
                'To' => $phone,
                'Body' => $message,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Twilio request failed with status '.$response->status().'.');
        }

        return (string) data_get($response->json(), 'sid');
    }

    private function message(?Complaint $complaint, string $title, ?string $body): string
    {
        if ($complaint) {
            return str($body ?: $title)
                ->prepend("GCMS: Complaint {$complaint->complaint_number} ")
                ->limit(155, '')
                ->toString();
        }

        return str($body ?: $title)->prepend('GCMS: ')->limit(155, '')->toString();
    }
}

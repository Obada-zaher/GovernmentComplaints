<?php

namespace App\Services\Notifications\Channels;

class PushTokenProviderDetector
{
    public const EXPO = 'expo';

    public const FCM = 'fcm';

    public function detect(string $token): string
    {
        return preg_match('/^(?:ExponentPushToken|ExpoPushToken)\[[^\]]+\]$/', $token) === 1
            ? self::EXPO
            : self::FCM;
    }
}

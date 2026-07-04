<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\Auth\OtpCodeNotification;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public const MAX_ATTEMPTS = 5;
    public const EXPIRES_IN_MINUTES = 10;

    public function generate(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     */
    public function createForUser(User $user, string $purpose): OtpCode
    {
        OtpCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $plainOtp = $this->generate();

        $otp = OtpCode::query()->create([
            'user_id' => $user->id,
            'phone' => $user->phone,
            'email' => $user->email,
            'code_hash' => Hash::make($plainOtp),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(self::EXPIRES_IN_MINUTES),
            'attempts' => 0,
        ]);

        $user->notify(new OtpCodeNotification($plainOtp, $purpose, self::EXPIRES_IN_MINUTES));

        return $otp;
    }

    /**
     * @return array{success: bool, message: string, otp?: OtpCode}
     */
    public function verify(User $user, string $plainOtp, string $purpose): array
    {
        $otp = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->latest()
            ->first();

        if (! $otp) {
            return [
                'success' => false,
                'message' => 'No valid OTP was found.',
            ];
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            return [
                'success' => false,
                'message' => 'The verification code is invalid or has expired.',
            ];
        }

        if ($otp->expires_at->isPast()) {
            return [
                'success' => false,
                'message' => 'The verification code is invalid or has expired.',
            ];
        }

        if (! Hash::check($plainOtp, $otp->code_hash)) {
            $otp->increment('attempts');

            if ($otp->fresh()->attempts >= self::MAX_ATTEMPTS) {
                $otp->forceFill(['used_at' => now()])->save();
            }

            return [
                'success' => false,
                'message' => 'The verification code is invalid or has expired.',
            ];
        }

        $otp->forceFill(['used_at' => now()])->save();

        return [
            'success' => true,
            'message' => 'OTP verified successfully.',
            'otp' => $otp,
        ];
    }
}

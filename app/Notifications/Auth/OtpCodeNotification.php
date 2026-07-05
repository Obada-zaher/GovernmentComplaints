<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OtpCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $otpCode,
        private readonly string $purpose,
        private readonly int $expiresInMinutes = 10,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Government Complaints Management System received a request for '.$this->purposeLabel().'.')
            ->line('Your verification code is: '.$this->otpCode)
            ->line('This code expires in '.$this->expiresInMinutes.' minutes.')
            ->line('If you did not request this code, please ignore this email.');
    }

    private function subject(): string
    {
        return match ($this->purpose) {
            'login' => 'GCMS Login Verification Code',
            'verify_email' => 'GCMS Email Verification Code',
            default => 'GCMS Account Verification Code',
        };
    }

    private function purposeLabel(): string
    {
        return match ($this->purpose) {
            'login' => 'login verification',
            'verify_email' => 'email verification',
            default => 'account verification',
        };
    }
}

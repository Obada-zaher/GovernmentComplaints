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
    ) {}

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
            ->greeting(trans('api.mail.greeting', ['name' => $notifiable->name]))
            ->line(trans('api.mail.otp.request', ['purpose' => $this->purposeLabel()]))
            ->line(trans('api.mail.otp.code', ['code' => $this->otpCode]))
            ->line(trans('api.mail.otp.expires', ['minutes' => $this->expiresInMinutes]))
            ->line(trans('api.mail.ignore_code'));
    }

    private function subject(): string
    {
        return match ($this->purpose) {
            'login' => trans('api.mail.subjects.login_otp'),
            'verify_email' => trans('api.mail.subjects.email_otp'),
            default => trans('api.mail.subjects.account_otp'),
        };
    }

    private function purposeLabel(): string
    {
        return match ($this->purpose) {
            'login' => trans('api.mail.purposes.login'),
            'verify_email' => trans('api.mail.purposes.email'),
            default => trans('api.mail.purposes.account'),
        };
    }
}

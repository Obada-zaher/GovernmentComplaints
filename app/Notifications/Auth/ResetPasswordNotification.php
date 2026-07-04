<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token)
    {
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
            ->subject('GCMS Password Reset')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Government Complaints Management System received a password reset request for your account.')
            ->line('Use this reset token in the API reset-password request:')
            ->line($this->token)
            ->line('If you did not request a password reset, please ignore this email.');
    }
}

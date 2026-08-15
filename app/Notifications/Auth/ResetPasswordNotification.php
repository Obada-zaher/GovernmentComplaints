<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

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
            ->subject(trans('api.mail.subjects.password_reset'))
            ->greeting(trans('api.mail.greeting', ['name' => $notifiable->name]))
            ->line(trans('api.mail.password_reset.request'))
            ->line(trans('api.mail.password_reset.use_token'))
            ->line($this->token)
            ->line(trans('api.mail.password_reset.ignore'));
    }
}

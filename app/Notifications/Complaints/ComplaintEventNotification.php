<?php

namespace App\Notifications\Complaints;

use App\Models\Complaint;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ComplaintEventNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ?Complaint $complaint,
        private readonly string $type,
        private readonly string $title,
        private readonly ?string $body = null,
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
        $message = (new MailMessage)
            ->subject($this->mailSubject())
            ->greeting(trans('api.mail.greeting', ['name' => $notifiable->name]))
            ->line($this->body ?? $this->title);

        if ($this->complaint) {
            $message
                ->line(trans('api.mail.complaint_number', ['number' => $this->complaint->complaint_number]))
                ->line(trans('api.mail.title', ['title' => $this->complaint->title]))
                ->line(trans('api.mail.status', ['status' => $this->complaint->status]));
        }

        return $message->line(trans('api.mail.sign_in_for_details'));
    }

    private function mailSubject(): string
    {
        return match ($this->type) {
            'complaint_assigned' => trans('api.mail.subjects.complaint_assigned'),
            'sla_breached' => trans('api.mail.subjects.sla_breached'),
            'complaint_resolved' => trans('api.mail.subjects.complaint_resolved'),
            default => $this->title,
        };
    }
}

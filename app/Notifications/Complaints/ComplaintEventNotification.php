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
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->body ?? $this->title);

        if ($this->complaint) {
            $message
                ->line('Complaint Number: '.$this->complaint->complaint_number)
                ->line('Title: '.$this->complaint->title)
                ->line('Status: '.$this->complaint->status);
        }

        return $message->line('Please sign in to the Government Complaint Management System for more details.');
    }

    private function mailSubject(): string
    {
        return match ($this->type) {
            'complaint_assigned' => 'Complaint Assignment Notification',
            'sla_breached' => 'SLA Breach Alert',
            'complaint_resolved' => 'Complaint Resolution Notification',
            default => $this->title,
        };
    }
}

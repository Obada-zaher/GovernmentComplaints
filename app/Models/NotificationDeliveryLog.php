<?php

namespace App\Models;

use Database\Factories\NotificationDeliveryLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDeliveryLog extends Model
{
    /** @use HasFactory<NotificationDeliveryLogFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_notification_id',
        'complaint_id',
        'channel',
        'type',
        'recipient',
        'status',
        'provider',
        'provider_message_id',
        'error_message',
        'payload',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function userNotification(): BelongsTo
    {
        return $this->belongsTo(UserNotification::class);
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }
}

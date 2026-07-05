<?php

namespace App\Models;

use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'database_enabled',
        'email_enabled',
        'push_enabled',
        'sms_enabled',
        'complaint_created',
        'complaint_assigned',
        'complaint_status_updated',
        'sla_breached',
        'complaint_resolved',
        'complaint_closed',
    ];

    /**
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return [
            'database_enabled' => true,
            'email_enabled' => true,
            'push_enabled' => true,
            'sms_enabled' => false,
            'complaint_created' => true,
            'complaint_assigned' => true,
            'complaint_status_updated' => true,
            'sla_breached' => true,
            'complaint_resolved' => true,
            'complaint_closed' => true,
        ];
    }

    protected function casts(): array
    {
        return [
            'database_enabled' => 'boolean',
            'email_enabled' => 'boolean',
            'push_enabled' => 'boolean',
            'sms_enabled' => 'boolean',
            'complaint_created' => 'boolean',
            'complaint_assigned' => 'boolean',
            'complaint_status_updated' => 'boolean',
            'sla_breached' => 'boolean',
            'complaint_resolved' => 'boolean',
            'complaint_closed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\OfflineSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineSubmission extends Model
{
    /** @use HasFactory<OfflineSubmissionFactory> */
    use HasFactory;

    protected $fillable = [
        'citizen_id',
        'client_uuid',
        'payload',
        'status',
        'synced_complaint_id',
        'error_message',
        'submitted_offline_at',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'submitted_offline_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function citizen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'citizen_id');
    }

    public function syncedComplaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class, 'synced_complaint_id');
    }
}

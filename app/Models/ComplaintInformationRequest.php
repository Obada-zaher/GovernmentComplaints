<?php

namespace App\Models;

use Database\Factories\ComplaintInformationRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplaintInformationRequest extends Model
{
    /** @use HasFactory<ComplaintInformationRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'complaint_id',
        'requested_by',
        'message',
        'status',
        'requested_at',
        'responded_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}

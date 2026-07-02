<?php

namespace App\Models;

use Database\Factories\ComplaintFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Complaint extends Model
{
    /** @use HasFactory<ComplaintFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'complaint_number',
        'citizen_id',
        'department_id',
        'category_id',
        'priority_id',
        'assigned_employee_id',
        'title',
        'description',
        'status',
        'latitude',
        'longitude',
        'address',
        'source',
        'client_uuid',
        'classification_confidence',
        'due_at',
        'first_response_at',
        'resolved_at',
        'closed_at',
        'is_sla_breached',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'classification_confidence' => 'decimal:4',
            'due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'is_sla_breached' => 'boolean',
        ];
    }

    public function citizen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'citizen_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ComplaintCategory::class, 'category_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class);
    }

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_employee_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ComplaintAttachment::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ComplaintStatusHistory::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ComplaintAssignment::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }
}

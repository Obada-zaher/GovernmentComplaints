<?php

namespace App\Models;

use Database\Factories\SlaRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SlaRule extends Model
{
    /** @use HasFactory<SlaRuleFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'department_id',
        'category_id',
        'priority_id',
        'response_time_hours',
        'resolution_time_hours',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'response_time_hours' => 'integer',
            'resolution_time_hours' => 'integer',
            'is_active' => 'boolean',
        ];
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
}

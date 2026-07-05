<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassificationLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'complaint_id',
        'title',
        'description',
        'predicted_department_id',
        'predicted_category_id',
        'confidence',
        'scores',
        'used_rules',
        'accepted',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'scores' => 'array',
            'used_rules' => 'array',
            'accepted' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function predictedDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'predicted_department_id');
    }

    public function predictedCategory(): BelongsTo
    {
        return $this->belongsTo(ComplaintCategory::class, 'predicted_category_id');
    }
}

<?php

namespace App\Models;

use Database\Factories\ComplaintClassificationRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplaintClassificationRule extends Model
{
    /** @use HasFactory<ComplaintClassificationRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'department_id',
        'category_id',
        'keyword',
        'weight',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
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
}

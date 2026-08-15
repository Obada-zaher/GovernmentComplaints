<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedDisplayFields;
use Database\Factories\ComplaintCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComplaintCategory extends Model
{
    /** @use HasFactory<ComplaintCategoryFactory> */
    use HasFactory, HasLocalizedDisplayFields, SoftDeletes;

    protected $fillable = [
        'department_id',
        'name',
        'name_ar',
        'code',
        'description',
        'description_ar',
        'keywords',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class, 'category_id');
    }

    public function slaRules(): HasMany
    {
        return $this->hasMany(SlaRule::class, 'category_id');
    }

    public function classificationRules(): HasMany
    {
        return $this->hasMany(ComplaintClassificationRule::class, 'category_id');
    }
}

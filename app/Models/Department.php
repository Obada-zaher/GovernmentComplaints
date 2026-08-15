<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedDisplayFields;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, HasLocalizedDisplayFields, SoftDeletes;

    protected $fillable = [
        'name',
        'name_ar',
        'code',
        'description',
        'description_ar',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(ComplaintCategory::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function slaRules(): HasMany
    {
        return $this->hasMany(SlaRule::class);
    }
}

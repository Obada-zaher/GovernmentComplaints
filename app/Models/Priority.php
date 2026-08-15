<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedDisplayFields;
use Database\Factories\PriorityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Priority extends Model
{
    /** @use HasFactory<PriorityFactory> */
    use HasFactory, HasLocalizedDisplayFields, SoftDeletes;

    protected $fillable = [
        'name',
        'name_ar',
        'code',
        'level',
        'color',
        'description',
        'description_ar',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
        ];
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function slaRules(): HasMany
    {
        return $this->hasMany(SlaRule::class);
    }
}

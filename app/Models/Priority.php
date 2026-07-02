<?php

namespace App\Models;

use Database\Factories\PriorityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Priority extends Model
{
    /** @use HasFactory<PriorityFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'level',
        'color',
        'description',
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

<?php

namespace App\Models;

use Database\Factories\ReportSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSnapshot extends Model
{
    /** @use HasFactory<ReportSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'filters',
        'data',
        'generated_by',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'data' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}

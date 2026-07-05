<?php

namespace App\Models;

use Database\Factories\UserDeviceTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserDeviceToken extends Model
{
    /** @use HasFactory<UserDeviceTokenFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'device_name',
        'app_version',
        'last_used_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

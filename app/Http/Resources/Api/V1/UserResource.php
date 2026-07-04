<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'national_id' => $this->when(array_key_exists('national_id', $this->resource->getAttributes()), $this->national_id),
            'role' => $this->role,
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ] : null),
            'is_active' => $this->when(array_key_exists('is_active', $this->resource->getAttributes()), (bool) $this->is_active),
            'phone_verified_at' => $this->when(array_key_exists('phone_verified_at', $this->resource->getAttributes()), $this->phone_verified_at?->toISOString()),
            'last_login_at' => $this->when(array_key_exists('last_login_at', $this->resource->getAttributes()), $this->last_login_at?->toISOString()),
        ];
    }
}

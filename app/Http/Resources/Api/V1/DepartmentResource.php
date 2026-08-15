<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->localizedName(),
            'code' => $this->code,
            'description' => $this->localizedDescription(),
            'is_active' => $this->when($request->is('api/v1/admin/*'), (bool) $this->is_active),
            'created_at' => $this->when($request->is('api/v1/admin/*'), $this->created_at?->toISOString()),
            'updated_at' => $this->when($request->is('api/v1/admin/*'), $this->updated_at?->toISOString()),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriorityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'level' => $this->level,
            'color' => $this->color,
            'description' => $this->description,
            'created_at' => $this->when($request->is('api/v1/admin/*'), $this->created_at?->toISOString()),
            'updated_at' => $this->when($request->is('api/v1/admin/*'), $this->updated_at?->toISOString()),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplaintCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ] : null),
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'keywords' => $this->keywords,
            'is_active' => $this->when($request->is('api/v1/admin/*'), (bool) $this->is_active),
            'created_at' => $this->when($request->is('api/v1/admin/*'), $this->created_at?->toISOString()),
            'updated_at' => $this->when($request->is('api/v1/admin/*'), $this->updated_at?->toISOString()),
        ];
    }
}

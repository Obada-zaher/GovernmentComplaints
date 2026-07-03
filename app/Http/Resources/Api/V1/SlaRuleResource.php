<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SlaRuleResource extends JsonResource
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
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'code' => $this->category->code,
            ] : null),
            'priority_id' => $this->priority_id,
            'priority' => $this->whenLoaded('priority', fn () => $this->priority ? [
                'id' => $this->priority->id,
                'name' => $this->priority->name,
                'code' => $this->priority->code,
                'level' => $this->priority->level,
            ] : null),
            'response_time_hours' => $this->response_time_hours,
            'resolution_time_hours' => $this->resolution_time_hours,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->when($request->is('api/v1/admin/*'), $this->created_at?->toISOString()),
            'updated_at' => $this->when($request->is('api/v1/admin/*'), $this->updated_at?->toISOString()),
        ];
    }
}

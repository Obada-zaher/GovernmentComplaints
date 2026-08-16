<?php

namespace App\Http\Resources\Api\V1\Citizen;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CitizenDashboardComplaintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'complaint_number' => $this->complaint_number,
            'title' => $this->title,
            'status' => $this->status,
            'department' => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->localizedName(),
            ] : null,
            'category' => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->localizedName(),
            ] : null,
            'priority' => $this->priority ? [
                'id' => $this->priority->id,
                'name' => $this->priority->localizedName(),
                'color' => $this->priority->color,
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'due_at' => $this->due_at?->toISOString(),
            'is_sla_breached' => (bool) $this->is_sla_breached,
        ];
    }
}

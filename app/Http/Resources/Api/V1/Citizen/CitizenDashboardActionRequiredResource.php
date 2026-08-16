<?php

namespace App\Http\Resources\Api\V1\Citizen;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CitizenDashboardActionRequiredResource extends JsonResource
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
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

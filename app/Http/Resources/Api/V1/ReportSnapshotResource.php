<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'filters' => $this->filters ?? [],
            'data' => $this->data ?? [],
            'generated_by' => $this->whenLoaded('generatedBy', fn () => $this->generatedBy ? [
                'id' => $this->generatedBy->id,
                'name' => $this->generatedBy->name,
                'email' => $this->generatedBy->email,
            ] : null),
            'generated_at' => $this->generated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

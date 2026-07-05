<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfflineSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'status' => $this->status,
            'submitted_offline_at' => $this->submitted_offline_at?->toISOString(),
            'synced_at' => $this->synced_at?->toISOString(),
            'error_message' => $this->error_message,
            'synced_complaint' => $this->whenLoaded('syncedComplaint', fn () => $this->syncedComplaint ? new ComplaintResource($this->syncedComplaint) : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

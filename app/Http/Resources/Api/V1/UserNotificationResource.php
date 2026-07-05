<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data ?? [],
            'complaint' => $this->whenLoaded('complaint', fn () => $this->complaint ? [
                'id' => $this->complaint->id,
                'complaint_number' => $this->complaint->complaint_number,
                'title' => $this->complaint->title,
                'status' => $this->complaint->status,
            ] : null),
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

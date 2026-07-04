<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplaintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isDetailed = ! ($request->is('api/v1/citizen/complaints') && $request->isMethod('GET'));

        return [
            'id' => $this->id,
            'complaint_number' => $this->complaint_number,
            'title' => $this->title,
            'description' => $this->when($isDetailed, $this->description),
            'status' => $this->status,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'address' => $this->address,
            'source' => $this->source,
            'classification_confidence' => $this->classification_confidence,
            'due_at' => $this->due_at?->toISOString(),
            'first_response_at' => $this->first_response_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'is_sla_breached' => (bool) $this->is_sla_breached,
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ] : null),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'code' => $this->category->code,
            ] : null),
            'priority' => $this->whenLoaded('priority', fn () => $this->priority ? [
                'id' => $this->priority->id,
                'name' => $this->priority->name,
                'code' => $this->priority->code,
                'level' => $this->priority->level,
                'color' => $this->priority->color,
            ] : null),
            'assigned_employee' => $this->whenLoaded('assignedEmployee', fn () => $this->assignedEmployee ? [
                'id' => $this->assignedEmployee->id,
                'name' => $this->assignedEmployee->name,
                'email' => $this->assignedEmployee->email,
                'phone' => $this->assignedEmployee->phone,
            ] : null),
            'attachments' => $this->whenLoaded('attachments', fn () => ComplaintAttachmentResource::collection($this->attachments)),
            'timeline' => $this->whenLoaded('statusHistories', fn () => ComplaintStatusHistoryResource::collection($this->statusHistories)),
            'status_histories' => $this->whenLoaded('statusHistories', fn () => ComplaintStatusHistoryResource::collection($this->statusHistories)),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

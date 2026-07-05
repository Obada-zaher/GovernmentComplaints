<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ComplaintAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'file_name' => $this->file_name,
            'file_path' => $this->file_path,
            'url' => $this->file_path ? Storage::disk($this->disk ?? 'public')->url($this->file_path) : null,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'disk' => $this->disk,
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy ? [
                'id' => $this->uploadedBy->id,
                'name' => $this->uploadedBy->name,
                'role' => $this->uploadedBy->role,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationDeliveryLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'type' => $this->type,
            'recipient' => $this->recipient,
            'status' => $this->status,
            'provider' => $this->provider,
            'provider_message_id' => $this->provider_message_id,
            'error_message' => $this->error_message,
            'payload' => $this->payload,
            'sent_at' => $this->sent_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'role' => $this->user->role,
            ] : null),
            'complaint' => $this->whenLoaded('complaint', fn (): ?array => $this->complaint ? [
                'id' => $this->complaint->id,
                'complaint_number' => $this->complaint->complaint_number,
                'title' => $this->complaint->title,
                'status' => $this->complaint->status,
            ] : null),
            'user_notification' => $this->whenLoaded('userNotification', fn (): ?array => $this->userNotification ? [
                'id' => $this->userNotification->id,
                'type' => $this->userNotification->type,
                'title' => $this->userNotification->title,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

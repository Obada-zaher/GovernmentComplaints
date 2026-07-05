<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationPreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'database_enabled' => (bool) $this->database_enabled,
            'email_enabled' => (bool) $this->email_enabled,
            'push_enabled' => (bool) $this->push_enabled,
            'sms_enabled' => (bool) $this->sms_enabled,
            'complaint_created' => (bool) $this->complaint_created,
            'complaint_assigned' => (bool) $this->complaint_assigned,
            'complaint_status_updated' => (bool) $this->complaint_status_updated,
            'sla_breached' => (bool) $this->sla_breached,
            'complaint_resolved' => (bool) $this->complaint_resolved,
            'complaint_closed' => (bool) $this->complaint_closed,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

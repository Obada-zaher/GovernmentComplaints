<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email_enabled' => ['sometimes', 'boolean'],
            'push_enabled' => ['sometimes', 'boolean'],
            'sms_enabled' => ['sometimes', 'boolean'],
            'complaint_created' => ['sometimes', 'boolean'],
            'complaint_assigned' => ['sometimes', 'boolean'],
            'complaint_status_updated' => ['sometimes', 'boolean'],
            'sla_breached' => ['sometimes', 'boolean'],
            'complaint_resolved' => ['sometimes', 'boolean'],
            'complaint_closed' => ['sometimes', 'boolean'],
        ];
    }
}

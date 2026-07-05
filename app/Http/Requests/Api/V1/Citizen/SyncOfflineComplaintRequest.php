<?php

namespace App\Http\Requests\Api\V1\Citizen;

use Illuminate\Foundation\Http\FormRequest;

class SyncOfflineComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'category_id' => ['nullable', 'exists:complaint_categories,id'],
            'priority_id' => ['nullable', 'exists:priorities,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:500'],
            'created_offline_at' => ['nullable', 'date'],
            'source' => ['nullable', 'in:offline_sync'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,pdf,doc,docx'],
        ];
    }
}

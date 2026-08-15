<?php

namespace App\Http\Requests\Api\V1\Citizen;

use Illuminate\Foundation\Http\FormRequest;

class SyncOfflineComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        $complaint = is_array($input['complaint'] ?? null) ? $input['complaint'] : [];
        $location = is_array($complaint['location'] ?? null) ? $complaint['location'] : [];
        $normalized = [];

        foreach (['title', 'description', 'department_id', 'category_id', 'priority_id'] as $field) {
            if (! array_key_exists($field, $input) && array_key_exists($field, $complaint)) {
                $normalized[$field] = $complaint[$field];
            }
        }

        foreach (['latitude' => 'lat', 'longitude' => 'lng', 'address' => 'address'] as $field => $locationField) {
            if (! array_key_exists($field, $input) && array_key_exists($locationField, $location)) {
                $normalized[$field] = $location[$locationField];
            }
        }

        if (! array_key_exists('client_uuid', $input)) {
            if (array_key_exists('client_uuid', $complaint)) {
                $normalized['client_uuid'] = $complaint['client_uuid'];
            } elseif (array_key_exists('client_ref', $complaint)) {
                $normalized['client_uuid'] = $complaint['client_ref'];
            }
        }

        $this->merge($normalized);
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

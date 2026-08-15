<?php

namespace App\Http\Requests\Api\V1\Citizen;

use Illuminate\Foundation\Http\FormRequest;

class StoreComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        $location = is_array($input['location'] ?? null) ? $input['location'] : [];
        $normalized = [];

        foreach (['latitude' => 'lat', 'longitude' => 'lng', 'address' => 'address'] as $field => $locationField) {
            if (! array_key_exists($field, $input) && array_key_exists($locationField, $location)) {
                $normalized[$field] = $location[$locationField];
            }
        }

        if (! array_key_exists('client_uuid', $input) && array_key_exists('client_ref', $input)) {
            $normalized['client_uuid'] = $input['client_ref'];
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'category_id' => ['nullable', 'exists:complaint_categories,id'],
            'priority_id' => ['nullable', 'exists:priorities,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'in:web,mobile'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,pdf,doc,docx'],
        ];
    }
}

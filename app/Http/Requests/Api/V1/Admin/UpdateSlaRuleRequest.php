<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSlaRuleRequest extends FormRequest
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
            'department_id' => ['nullable', 'exists:departments,id'],
            'category_id' => ['nullable', 'exists:complaint_categories,id'],
            'priority_id' => ['sometimes', 'required', 'exists:priorities,id'],
            'response_time_hours' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'resolution_time_hours' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

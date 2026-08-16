<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\SlaRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSlaRuleRequest extends FormRequest
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
            'priority_id' => ['required', 'exists:priorities,id'],
            'response_time_hours' => ['required', 'integer', 'min:1', 'max:10000'],
            'resolution_time_hours' => ['required', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $exists = SlaRule::query()
                ->where('department_id', $this->input('department_id'))
                ->where('category_id', $this->input('category_id'))
                ->where('priority_id', $this->integer('priority_id'))
                ->exists();

            if ($exists) {
                $validator->errors()->add('priority_id', 'An SLA rule already exists for the selected department, category, and priority.');
            }
        }];
    }
}

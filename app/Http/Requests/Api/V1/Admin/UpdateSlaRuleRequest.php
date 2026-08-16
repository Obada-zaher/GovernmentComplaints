<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\SlaRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var SlaRule $slaRule */
            $slaRule = $this->route('sla_rule');
            $departmentId = $this->exists('department_id')
                ? $this->input('department_id')
                : $slaRule->department_id;
            $categoryId = $this->exists('category_id')
                ? $this->input('category_id')
                : $slaRule->category_id;
            $priorityId = $this->exists('priority_id')
                ? $this->integer('priority_id')
                : $slaRule->priority_id;

            $exists = SlaRule::query()
                ->where('department_id', $departmentId)
                ->where('category_id', $categoryId)
                ->where('priority_id', $priorityId)
                ->whereKeyNot($slaRule->id)
                ->exists();

            if ($exists) {
                $validator->errors()->add('priority_id', 'An SLA rule already exists for the selected department, category, and priority.');
            }
        }];
    }
}

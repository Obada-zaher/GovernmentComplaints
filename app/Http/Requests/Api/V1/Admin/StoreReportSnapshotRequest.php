<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreReportSnapshotRequest extends FormRequest
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
            'type' => ['required', 'string', 'max:100'],
            'filters' => ['nullable', 'array'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date', 'after_or_equal:filters.date_from'],
            'filters.department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'filters.category_id' => ['nullable', 'integer', 'exists:complaint_categories,id'],
            'filters.priority_id' => ['nullable', 'integer', 'exists:priorities,id'],
            'filters.assigned_employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'filters.citizen_id' => ['nullable', 'integer', 'exists:users,id'],
            'filters.status' => ['nullable', 'string'],
            'filters.is_sla_breached' => ['nullable', 'boolean'],
            'filters.group_by' => ['nullable', 'in:day,week,month'],
        ];
    }
}

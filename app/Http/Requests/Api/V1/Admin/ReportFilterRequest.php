<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportFilterRequest extends FormRequest
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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'category_id' => ['nullable', 'integer', 'exists:complaint_categories,id'],
            'priority_id' => ['nullable', 'integer', 'exists:priorities,id'],
            'assigned_employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'citizen_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', Rule::in([
                'submitted',
                'under_review',
                'assigned',
                'in_progress',
                'waiting_citizen',
                'resolved',
                'closed',
                'rejected',
                'escalated',
            ])],
            'is_sla_breached' => ['nullable', 'boolean'],
            'group_by' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}

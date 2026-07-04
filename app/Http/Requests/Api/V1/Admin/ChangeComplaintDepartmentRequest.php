<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ChangeComplaintDepartmentRequest extends FormRequest
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
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'category_id' => ['nullable', 'integer', 'exists:complaint_categories,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

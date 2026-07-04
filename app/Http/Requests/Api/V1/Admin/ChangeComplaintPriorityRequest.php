<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ChangeComplaintPriorityRequest extends FormRequest
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
            'priority_id' => ['required', 'integer', 'exists:priorities,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateComplaintStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in([
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
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

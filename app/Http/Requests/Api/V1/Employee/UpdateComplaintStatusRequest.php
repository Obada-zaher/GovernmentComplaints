<?php

namespace App\Http\Requests\Api\V1\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateComplaintStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('status') === 'waiting_citizen' && is_string($this->input('note'))) {
            $this->merge(['note' => trim($this->input('note'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                'under_review',
                'in_progress',
                'waiting_citizen',
                'resolved',
                'escalated',
            ])],
            'note' => [Rule::requiredIf($this->input('status') === 'waiting_citizen'), 'nullable', 'string', 'max:1000'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Citizen;

use Illuminate\Foundation\Http\FormRequest;

class CitizenInformationResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim($this->input('message'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
            'attachments' => ['prohibited'],
        ];
    }
}

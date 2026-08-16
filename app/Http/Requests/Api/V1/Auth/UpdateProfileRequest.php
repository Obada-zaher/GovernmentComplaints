<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    private const EDITABLE_FIELDS = ['name', 'phone'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'phone')->ignore($this->user()),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unknownFields = array_diff(array_keys($this->all()), self::EDITABLE_FIELDS);

            if ($unknownFields !== []) {
                $validator->errors()->add(
                    'fields',
                    'The following fields are not editable: '.implode(', ', $unknownFields).'.',
                );
            }
        });
    }
}

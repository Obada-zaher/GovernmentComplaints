<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:255', 'unique:users,phone'],
            'national_id' => ['nullable', 'string', 'max:255', 'unique:users,national_id'],
            'password' => ['required', 'confirmed', 'min:8'],
            'role' => ['required', Rule::in(['citizen', 'employee', 'admin'])],
            'department_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', Rule::in([true, false, 1, 0, 'true', 'false'])],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($this->input('role') !== 'employee') {
                return;
            }

            $departmentId = $this->input('department_id');

            if (! $departmentId) {
                $validator->errors()->add('department_id', 'The department field is required for employees.');

                return;
            }

            if (! Department::query()->whereKey($departmentId)->where('is_active', true)->exists()) {
                $validator->errors()->add('department_id', 'The selected department must exist and be active.');
            }
        }];
    }
}

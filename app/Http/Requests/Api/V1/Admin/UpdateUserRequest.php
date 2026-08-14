<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('users', 'phone')->ignore($user)],
            'national_id' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('users', 'national_id')->ignore($user)],
            'role' => ['sometimes', 'required', Rule::in(['citizen', 'employee', 'admin'])],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'is_active' => ['missing'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            /** @var User $user */
            $user = $this->route('user');
            $role = $this->input('role', $user->role);

            if ($role !== 'employee') {
                return;
            }

            $departmentId = $this->has('department_id')
                ? $this->input('department_id')
                : $user->department_id;

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

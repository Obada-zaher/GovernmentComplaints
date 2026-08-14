<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User
    {
        return User::query()->create(array_merge(
            $this->attributes($data),
            [
                'password' => Hash::make($data['password']),
                'is_active' => $this->booleanValue($data['is_active'] ?? true),
            ],
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User
    {
        $user->update($this->attributes($data, $user));

        return $user->fresh('department');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?User $user = null): array
    {
        $role = $data['role'] ?? $user?->role;
        $attributes = collect($data)
            ->only(['name', 'email', 'phone', 'national_id', 'role'])
            ->all();

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = $this->booleanValue($data['is_active']);
        }

        $attributes['department_id'] = $role === 'employee'
            ? ($data['department_id'] ?? $user?->department_id)
            : null;

        return $attributes;
    }

    private function booleanValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

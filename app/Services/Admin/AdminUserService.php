<?php

namespace App\Services\Admin;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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
    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor): User {
            $hasActiveAssignedWork = $this->hasActiveAssignedWorkForUpdate($user->id);
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $newRole = $data['role'] ?? $user->role;
            $newAttributes = $this->attributes($data, $user);

            if ($user->id === $actor->id && $user->role === 'admin' && $newRole !== 'admin') {
                throw ValidationException::withMessages([
                    'role' => ['You cannot change your own admin role.'],
                ]);
            }

            $this->ensureActiveAdminRemains($user, $newRole !== 'admin');
            $this->ensureEmployeeWorkIsNotOrphaned($user, $newRole, $newAttributes['department_id'], $hasActiveAssignedWork);
            $user->update($newAttributes);

            return $user->fresh('department');
        });
    }

    public function updateStatus(User $user, bool $isActive, User $actor): User
    {
        return DB::transaction(function () use ($user, $isActive, $actor): User {
            $hasActiveAssignedWork = $this->hasActiveAssignedWorkForUpdate($user->id);
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! $isActive && $user->id === $actor->id) {
                throw ValidationException::withMessages([
                    'is_active' => ['You cannot deactivate your own account.'],
                ]);
            }

            if (! $isActive) {
                if ($user->is_active) {
                    $this->ensureActiveAdminRemains($user, true);
                    $this->ensureEmployeeWorkIsNotOrphaned($user, $user->role, $user->department_id, $hasActiveAssignedWork, true);
                    $user->forceFill(['is_active' => false])->save();
                }

                $user->tokens()->delete();
            }

            if ($isActive && ! $user->is_active) {
                $user->forceFill(['is_active' => true])->save();
            }

            return $user->fresh('department');
        });
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

        $attributes['department_id'] = $role === 'employee'
            ? ($data['department_id'] ?? $user?->department_id)
            : null;

        return $attributes;
    }

    private function booleanValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function ensureActiveAdminRemains(User $user, bool $removingActiveAdmin): void
    {
        if (! $removingActiveAdmin || $user->role !== 'admin' || ! $user->is_active) {
            return;
        }

        $activeAdmins = User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->lockForUpdate()
            ->get();

        if ($activeAdmins->count() <= 1) {
            throw ValidationException::withMessages([
                'role' => ['At least one active admin account must remain.'],
            ]);
        }
    }

    private function ensureEmployeeWorkIsNotOrphaned(User $user, string $newRole, ?int $newDepartmentId, bool $hasActiveAssignedWork, bool $deactivating = false): void
    {
        if ($user->role !== 'employee') {
            return;
        }

        $wouldOrphan = $deactivating || $newRole !== 'employee' || (int) $newDepartmentId !== (int) $user->department_id;

        if (! $wouldOrphan) {
            return;
        }

        if (! $hasActiveAssignedWork) {
            return;
        }

        $field = $deactivating ? 'is_active' : ($newRole !== 'employee' ? 'role' : 'department_id');

        throw ValidationException::withMessages([
            $field => ['Reassign the employee\'s active complaints before changing this account.'],
        ]);
    }

    private function hasActiveAssignedWorkForUpdate(int $userId): bool
    {
        return Complaint::query()
            ->where('assigned_employee_id', $userId)
            ->whereIn('status', ['assigned', 'in_progress', 'waiting_citizen', 'escalated'])
            ->lockForUpdate()
            ->exists();
    }
}

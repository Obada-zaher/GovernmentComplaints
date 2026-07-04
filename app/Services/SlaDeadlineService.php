<?php

namespace App\Services;

use App\Models\SlaRule;
use Illuminate\Support\Carbon;

class SlaDeadlineService
{
    public function calculate(?int $departmentId, ?int $categoryId, ?int $priorityId): ?Carbon
    {
        if (! $priorityId) {
            return null;
        }

        $rule = $this->findRule($departmentId, $categoryId, $priorityId);

        return $rule ? now()->addHours($rule->resolution_time_hours) : null;
    }

    private function findRule(?int $departmentId, ?int $categoryId, int $priorityId): ?SlaRule
    {
        if ($departmentId && $categoryId) {
            $rule = SlaRule::query()
                ->where('is_active', true)
                ->where('department_id', $departmentId)
                ->where('category_id', $categoryId)
                ->where('priority_id', $priorityId)
                ->first();

            if ($rule) {
                return $rule;
            }
        }

        if ($departmentId) {
            $rule = SlaRule::query()
                ->where('is_active', true)
                ->where('department_id', $departmentId)
                ->whereNull('category_id')
                ->where('priority_id', $priorityId)
                ->first();

            if ($rule) {
                return $rule;
            }
        }

        return SlaRule::query()
            ->where('is_active', true)
            ->whereNull('department_id')
            ->whereNull('category_id')
            ->where('priority_id', $priorityId)
            ->first();
    }
}

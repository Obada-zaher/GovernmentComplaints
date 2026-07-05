<?php

namespace App\Services\Sla;

use App\Models\Complaint;
use App\Models\SlaRule;
use Illuminate\Support\Carbon;

class SlaDeadlineService
{
    public function calculateForComplaint(Complaint $complaint): ?Carbon
    {
        return $this->calculate(
            $complaint->department_id,
            $complaint->category_id,
            $complaint->priority_id,
        );
    }

    public function calculate(?int $departmentId, ?int $categoryId, ?int $priorityId): ?Carbon
    {
        if (! $priorityId) {
            return null;
        }

        $rule = $this->findRule($departmentId, $categoryId, $priorityId);

        return $rule ? now()->addHours($rule->resolution_time_hours) : null;
    }

    public function findRule(?int $departmentId, ?int $categoryId, int $priorityId): ?SlaRule
    {
        $matchers = [
            ['department_id' => $departmentId, 'category_id' => $categoryId, 'priority_id' => $priorityId],
            ['department_id' => $departmentId, 'category_id' => null, 'priority_id' => $priorityId],
            ['department_id' => null, 'category_id' => $categoryId, 'priority_id' => $priorityId],
            ['department_id' => null, 'category_id' => null, 'priority_id' => $priorityId],
        ];

        foreach ($matchers as $matcher) {
            if (($matcher['department_id'] === null && $matcher['category_id'] === null && ! $priorityId)
                || ($matcher['department_id'] !== null && ! $departmentId)
                || ($matcher['category_id'] !== null && ! $categoryId)) {
                continue;
            }

            $rule = SlaRule::query()
                ->where('is_active', true)
                ->where('priority_id', $matcher['priority_id'])
                ->when(
                    $matcher['department_id'] === null,
                    fn ($query) => $query->whereNull('department_id'),
                    fn ($query) => $query->where('department_id', $matcher['department_id']),
                )
                ->when(
                    $matcher['category_id'] === null,
                    fn ($query) => $query->whereNull('category_id'),
                    fn ($query) => $query->where('category_id', $matcher['category_id']),
                )
                ->first();

            if ($rule) {
                return $rule;
            }
        }

        return null;
    }
}

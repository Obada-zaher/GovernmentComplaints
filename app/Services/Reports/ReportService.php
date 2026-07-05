<?php

namespace App\Services\Reports;

use App\Models\Complaint;
use App\Models\Department;
use App\Models\Priority;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReportService
{
    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        'submitted',
        'under_review',
        'assigned',
        'in_progress',
        'waiting_citizen',
        'resolved',
        'closed',
        'rejected',
        'escalated',
    ];

    /**
     * @var array<int, string>
     */
    private const OPEN_STATUSES = [
        'submitted',
        'under_review',
        'assigned',
        'in_progress',
        'waiting_citizen',
        'escalated',
    ];

    /**
     * @var array<int, string>
     */
    private const SLA_TERMINAL_STATUSES = ['resolved', 'closed', 'rejected'];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Complaint>
     */
    public function baseQuery(array $filters = [], bool $withDateFilters = true): Builder
    {
        return Complaint::query()
            ->when($withDateFilters && ! empty($filters['date_from']), fn ($query) => $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay()))
            ->when($withDateFilters && ! empty($filters['date_to']), fn ($query) => $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay()))
            ->when(! empty($filters['department_id']), fn ($query) => $query->where('department_id', (int) $filters['department_id']))
            ->when(! empty($filters['category_id']), fn ($query) => $query->where('category_id', (int) $filters['category_id']))
            ->when(! empty($filters['priority_id']), fn ($query) => $query->where('priority_id', (int) $filters['priority_id']))
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['assigned_employee_id']), fn ($query) => $query->where('assigned_employee_id', (int) $filters['assigned_employee_id']))
            ->when(! empty($filters['citizen_id']), fn ($query) => $query->where('citizen_id', (int) $filters['citizen_id']))
            ->when(array_key_exists('is_sla_breached', $filters) && $filters['is_sla_breached'] !== null && $filters['is_sla_breached'] !== '', function ($query) use ($filters): void {
                $query->where('is_sla_breached', filter_var($filters['is_sla_breached'], FILTER_VALIDATE_BOOLEAN));
            });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function overview(array $filters = []): array
    {
        $complaints = $this->baseQuery($filters)->get();
        $total = $complaints->count();

        return [
            'total_complaints' => $total,
            'open_complaints' => $complaints->whereIn('status', self::OPEN_STATUSES)->count(),
            'resolved_complaints' => $complaints->where('status', 'resolved')->count(),
            'closed_complaints' => $complaints->where('status', 'closed')->count(),
            'rejected_complaints' => $complaints->where('status', 'rejected')->count(),
            'escalated_complaints' => $complaints->where('status', 'escalated')->count(),
            'sla_breached_complaints' => $complaints->where('is_sla_breached', true)->count(),
            'sla_breach_rate' => $this->percentage($complaints->where('is_sla_breached', true)->count(), $total),
            'average_first_response_minutes' => $this->averageMinutes($complaints, 'created_at', 'first_response_at'),
            'average_resolution_minutes' => $this->averageMinutes($complaints, 'created_at', 'resolved_at'),
            'new_complaints_today' => $complaints->filter(fn (Complaint $complaint) => $complaint->created_at?->isToday())->count(),
            'new_complaints_this_week' => $complaints->filter(fn (Complaint $complaint) => $complaint->created_at?->isCurrentWeek())->count(),
            'new_complaints_this_month' => $complaints->filter(fn (Complaint $complaint) => $complaint->created_at?->isCurrentMonth())->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function complaintsByStatus(array $filters = []): array
    {
        $complaints = $this->baseQuery($filters)->get();
        $total = $complaints->count();
        $counts = $complaints->countBy('status');

        return collect(self::STATUSES)
            ->map(fn (string $status): array => [
                'status' => $status,
                'count' => (int) ($counts[$status] ?? 0),
                'percentage' => $this->percentage((int) ($counts[$status] ?? 0), $total),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function complaintsByDepartment(array $filters = []): array
    {
        $complaints = $this->baseQuery($filters)->get()->groupBy('department_id');
        $departments = Department::query()
            ->when(! empty($filters['department_id']), fn ($query) => $query->where('id', (int) $filters['department_id']))
            ->orderBy('name')
            ->get();

        return $departments->map(function (Department $department) use ($complaints): array {
            $items = $complaints->get($department->id, collect());
            $total = $items->count();

            return [
                'department' => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'code' => $department->code,
                ],
                'total' => $total,
                'open' => $items->whereIn('status', self::OPEN_STATUSES)->count(),
                'resolved' => $items->where('status', 'resolved')->count(),
                'closed' => $items->where('status', 'closed')->count(),
                'sla_breached' => $items->where('is_sla_breached', true)->count(),
                'sla_breach_rate' => $this->percentage($items->where('is_sla_breached', true)->count(), $total),
                'average_resolution_minutes' => $this->averageMinutes($items, 'created_at', 'resolved_at'),
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function complaintsByPriority(array $filters = []): array
    {
        $complaints = $this->baseQuery($filters)->get()->groupBy('priority_id');
        $priorities = Priority::query()
            ->when(! empty($filters['priority_id']), fn ($query) => $query->where('id', (int) $filters['priority_id']))
            ->orderByDesc('level')
            ->get();

        return $priorities->map(function (Priority $priority) use ($complaints): array {
            $items = $complaints->get($priority->id, collect());
            $total = $items->count();

            return [
                'priority' => [
                    'id' => $priority->id,
                    'name' => $priority->name,
                    'code' => $priority->code,
                    'level' => $priority->level,
                ],
                'total' => $total,
                'open' => $items->whereIn('status', self::OPEN_STATUSES)->count(),
                'resolved' => $items->where('status', 'resolved')->count(),
                'sla_breached' => $items->where('is_sla_breached', true)->count(),
                'sla_breach_rate' => $this->percentage($items->where('is_sla_breached', true)->count(), $total),
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function slaPerformance(array $filters = []): array
    {
        $complaints = $this->baseQuery($filters)
            ->whereNotNull('due_at')
            ->where('status', '!=', 'rejected')
            ->get();

        $breached = $complaints->filter(fn (Complaint $complaint): bool => $this->isSlaBreachedForReport($complaint));
        $total = $complaints->count();

        return [
            'total_with_sla' => $total,
            'within_sla' => $total - $breached->count(),
            'breached' => $breached->count(),
            'breach_rate' => $this->percentage($breached->count(), $total),
            'average_delay_minutes_for_breached' => $this->averageDelayMinutes($breached),
            'by_department' => $this->slaBreakdownByDepartment($complaints),
            'by_priority' => $this->slaBreakdownByPriority($complaints),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function employeePerformance(array $filters = []): array
    {
        $complaints = $this->baseQuery($filters)
            ->whereNotNull('assigned_employee_id')
            ->get()
            ->groupBy('assigned_employee_id');

        return User::query()
            ->with('department')
            ->where('role', 'employee')
            ->when(! empty($filters['department_id']), fn ($query) => $query->where('department_id', (int) $filters['department_id']))
            ->orderBy('name')
            ->get()
            ->map(function (User $employee) use ($complaints): array {
                $items = $complaints->get($employee->id, collect());
                $assignedTotal = $items->count();
                $resolved = $items->where('status', 'resolved')->count();
                $closed = $items->where('status', 'closed')->count();
                $slaBreached = $items->filter(fn (Complaint $complaint): bool => $this->isSlaBreachedForReport($complaint))->count();

                return [
                    'employee' => [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'email' => $employee->email,
                    ],
                    'department' => $employee->department ? [
                        'id' => $employee->department->id,
                        'name' => $employee->department->name,
                    ] : null,
                    'assigned_total' => $assignedTotal,
                    'in_progress' => $items->where('status', 'in_progress')->count(),
                    'resolved' => $resolved,
                    'closed' => $closed,
                    'sla_breached' => $slaBreached,
                    'average_first_response_minutes' => $this->averageMinutes($items, 'created_at', 'first_response_at'),
                    'average_resolution_minutes' => $this->averageMinutes($items, 'created_at', 'resolved_at'),
                    'resolution_rate' => $this->percentage($resolved, $assignedTotal),
                    'sla_success_rate' => $this->percentage($assignedTotal - $slaBreached, $assignedTotal),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function complaintTrends(array $filters = []): array
    {
        $groupBy = $filters['group_by'] ?? 'day';
        $complaints = $this->baseQuery($filters, false)->get();
        $dateFrom = ! empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : null;
        $dateTo = ! empty($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : null;

        $periods = [];

        foreach ($complaints as $complaint) {
            $this->incrementTrend($periods, $complaint->created_at, 'created', $groupBy, $dateFrom, $dateTo);
            $this->incrementTrend($periods, $complaint->resolved_at, 'resolved', $groupBy, $dateFrom, $dateTo);
            $this->incrementTrend($periods, $complaint->closed_at, 'closed', $groupBy, $dateFrom, $dateTo);

            if ($this->isSlaBreachedForReport($complaint)) {
                $this->incrementTrend($periods, $complaint->updated_at ?? $complaint->due_at, 'sla_breached', $groupBy, $dateFrom, $dateTo);
            }
        }

        ksort($periods);

        return array_values($periods);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Complaint>
     */
    public function slaBreachesQuery(array $filters = []): Builder
    {
        return $this->baseQuery($filters)
            ->with(['department', 'priority', 'assignedEmployee'])
            ->whereNotNull('due_at')
            ->where(function ($query): void {
                $query->where('is_sla_breached', true)
                    ->orWhere(function ($overdueQuery): void {
                        $overdueQuery
                            ->where('due_at', '<', now())
                            ->whereNotIn('status', self::SLA_TERMINAL_STATUSES);
                    });
            })
            ->latest('due_at');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|array<int, array<string, mixed>>
     */
    public function generateByType(string $type, array $filters = []): array
    {
        return match ($type) {
            'overview' => $this->overview($filters),
            'complaints_by_status' => $this->complaintsByStatus($filters),
            'complaints_by_department' => $this->complaintsByDepartment($filters),
            'complaints_by_priority' => $this->complaintsByPriority($filters),
            'sla_performance' => $this->slaPerformance($filters),
            'employee_performance' => $this->employeePerformance($filters),
            'complaint_trends' => $this->complaintTrends($filters),
            default => $this->overview($filters),
        };
    }

    /**
     * @param  Collection<int, Complaint>  $complaints
     * @return array<int, array<string, mixed>>
     */
    private function slaBreakdownByDepartment(Collection $complaints): array
    {
        $grouped = $complaints->groupBy('department_id');

        return Department::query()
            ->orderBy('name')
            ->get()
            ->map(function (Department $department) use ($grouped): array {
                $items = $grouped->get($department->id, collect());
                $breached = $items->filter(fn (Complaint $complaint): bool => $this->isSlaBreachedForReport($complaint))->count();

                return [
                    'department' => [
                        'id' => $department->id,
                        'name' => $department->name,
                        'code' => $department->code,
                    ],
                    'total_with_sla' => $items->count(),
                    'breached' => $breached,
                    'breach_rate' => $this->percentage($breached, $items->count()),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Complaint>  $complaints
     * @return array<int, array<string, mixed>>
     */
    private function slaBreakdownByPriority(Collection $complaints): array
    {
        $grouped = $complaints->groupBy('priority_id');

        return Priority::query()
            ->orderByDesc('level')
            ->get()
            ->map(function (Priority $priority) use ($grouped): array {
                $items = $grouped->get($priority->id, collect());
                $breached = $items->filter(fn (Complaint $complaint): bool => $this->isSlaBreachedForReport($complaint))->count();

                return [
                    'priority' => [
                        'id' => $priority->id,
                        'name' => $priority->name,
                        'code' => $priority->code,
                        'level' => $priority->level,
                    ],
                    'total_with_sla' => $items->count(),
                    'breached' => $breached,
                    'breach_rate' => $this->percentage($breached, $items->count()),
                ];
            })
            ->values()
            ->all();
    }

    private function isSlaBreachedForReport(Complaint $complaint): bool
    {
        return (bool) $complaint->is_sla_breached
            || ($complaint->due_at && $complaint->due_at->lt(now()) && ! in_array($complaint->status, self::SLA_TERMINAL_STATUSES, true));
    }

    /**
     * @param  Collection<int, Complaint>  $complaints
     */
    private function averageMinutes(Collection $complaints, string $fromField, string $toField): ?int
    {
        $values = $complaints
            ->filter(fn (Complaint $complaint): bool => $complaint->{$fromField} && $complaint->{$toField})
            ->map(fn (Complaint $complaint): int => max(0, (int) $complaint->{$fromField}->diffInMinutes($complaint->{$toField})));

        return $values->isEmpty() ? null : (int) round($values->avg());
    }

    /**
     * @param  Collection<int, Complaint>  $complaints
     */
    private function averageDelayMinutes(Collection $complaints): ?int
    {
        $values = $complaints
            ->filter(fn (Complaint $complaint): bool => (bool) $complaint->due_at)
            ->map(function (Complaint $complaint): int {
                $endAt = $complaint->resolved_at ?? $complaint->closed_at ?? now();

                return max(0, (int) $complaint->due_at->diffInMinutes($endAt));
            });

        return $values->isEmpty() ? null : (int) round($values->avg());
    }

    /**
     * @param  array<string, array<string, mixed>>  $periods
     */
    private function incrementTrend(array &$periods, ?Carbon $date, string $field, string $groupBy, ?Carbon $dateFrom, ?Carbon $dateTo): void
    {
        if (! $date || ($dateFrom && $date->lt($dateFrom)) || ($dateTo && $date->gt($dateTo))) {
            return;
        }

        $period = $this->periodKey($date, $groupBy);

        if (! isset($periods[$period])) {
            $periods[$period] = [
                'period' => $period,
                'created' => 0,
                'resolved' => 0,
                'closed' => 0,
                'sla_breached' => 0,
            ];
        }

        $periods[$period][$field]++;
    }

    private function periodKey(Carbon $date, string $groupBy): string
    {
        return match ($groupBy) {
            'month' => $date->format('Y-m'),
            'week' => $date->copy()->startOfWeek()->format('Y-m-d'),
            default => $date->format('Y-m-d'),
        };
    }

    private function percentage(int $part, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 2);
    }
}

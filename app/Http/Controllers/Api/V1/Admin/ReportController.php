<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReportFilterRequest;
use App\Http\Requests\Api\V1\Admin\StoreReportSnapshotRequest;
use App\Http\Resources\Api\V1\ReportSnapshotResource;
use App\Http\Responses\ApiResponse;
use App\Models\ReportSnapshot;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReportService $reportService) {}

    public function overview(ReportFilterRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return $this->successResponse(
            'Overview report retrieved successfully.',
            $this->reportService->overview($filters),
            200,
            ['filters' => $filters],
        );
    }

    public function complaintsByStatus(ReportFilterRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return $this->successResponse(
            'Complaints by status report retrieved successfully.',
            $this->reportService->complaintsByStatus($filters),
            200,
            ['filters' => $filters],
        );
    }

    public function complaintsByDepartment(ReportFilterRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return $this->successResponse(
            'Complaints by department report retrieved successfully.',
            $this->reportService->complaintsByDepartment($filters),
            200,
            ['filters' => $filters],
        );
    }

    public function complaintsByPriority(ReportFilterRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return $this->successResponse(
            'Complaints by priority report retrieved successfully.',
            $this->reportService->complaintsByPriority($filters),
            200,
            ['filters' => $filters],
        );
    }

    public function slaPerformance(ReportFilterRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return $this->successResponse(
            'SLA performance report retrieved successfully.',
            $this->reportService->slaPerformance($filters),
            200,
            ['filters' => $filters],
        );
    }

    public function employeePerformance(ReportFilterRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return $this->successResponse(
            'Employee performance report retrieved successfully.',
            $this->reportService->employeePerformance($filters),
            200,
            ['filters' => $filters],
        );
    }

    public function complaintTrends(ReportFilterRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $filters['group_by'] = $filters['group_by'] ?? 'day';

        return $this->successResponse(
            'Complaint trends report retrieved successfully.',
            $this->reportService->complaintTrends($filters),
            200,
            ['filters' => $filters],
        );
    }

    public function slaBreaches(ReportFilterRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $breaches = $this->reportService
            ->slaBreachesQuery($filters)
            ->paginate($this->perPage($request));

        return $this->successResponse(
            'SLA breaches report retrieved successfully.',
            [
                'complaints' => $breaches->getCollection()->map(fn ($complaint): array => [
                    'id' => $complaint->id,
                    'complaint_number' => $complaint->complaint_number,
                    'title' => $complaint->title,
                    'status' => $complaint->status,
                    'department' => $complaint->department ? [
                        'id' => $complaint->department->id,
                        'name' => $complaint->department->name,
                        'code' => $complaint->department->code,
                    ] : null,
                    'priority' => $complaint->priority ? [
                        'id' => $complaint->priority->id,
                        'name' => $complaint->priority->name,
                        'code' => $complaint->priority->code,
                        'level' => $complaint->priority->level,
                    ] : null,
                    'assigned_employee' => $complaint->assignedEmployee ? [
                        'id' => $complaint->assignedEmployee->id,
                        'name' => $complaint->assignedEmployee->name,
                        'email' => $complaint->assignedEmployee->email,
                    ] : null,
                    'due_at' => $complaint->due_at?->toISOString(),
                    'created_at' => $complaint->created_at?->toISOString(),
                    'delay_minutes' => $complaint->due_at ? max(0, (int) $complaint->due_at->diffInMinutes($complaint->resolved_at ?? $complaint->closed_at ?? now())) : null,
                ])->values(),
            ],
            200,
            array_merge(['filters' => $filters], $this->paginationMeta($breaches)),
        );
    }

    public function storeSnapshot(StoreReportSnapshotRequest $request): JsonResponse
    {
        $data = $request->validated();
        $filters = $data['filters'] ?? [];

        $snapshot = ReportSnapshot::query()->create([
            'type' => $data['type'],
            'filters' => $filters,
            'data' => $this->reportService->generateByType($data['type'], $filters),
            'generated_by' => $request->user()->id,
            'generated_at' => now(),
        ]);

        return $this->successResponse(
            'Report snapshot created successfully.',
            new ReportSnapshotResource($snapshot->load('generatedBy')),
            201,
        );
    }

    public function snapshots(ReportFilterRequest $request): JsonResponse
    {
        $snapshots = ReportSnapshot::query()
            ->with('generatedBy')
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->query('type')))
            ->latest()
            ->paginate($this->perPage($request));

        return $this->successResponse(
            'Report snapshots retrieved successfully.',
            ['snapshots' => ReportSnapshotResource::collection($snapshots->getCollection())],
            200,
            $this->paginationMeta($snapshots),
        );
    }

    public function showSnapshot(ReportSnapshot $reportSnapshot): JsonResponse
    {
        return $this->successResponse(
            'Report snapshot retrieved successfully.',
            new ReportSnapshotResource($reportSnapshot->load('generatedBy')),
        );
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 15), 1), 100);
    }

    /**
     * @return array<string, int|null>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];
    }
}

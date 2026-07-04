<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Employee\UpdateComplaintStatusRequest;
use App\Http\Resources\Api\V1\ComplaintResource;
use App\Http\Responses\ApiResponse;
use App\Models\Complaint;
use App\Services\Complaints\ComplaintStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ComplaintController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ComplaintStatusService $statusService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $employee = $request->user();
        $scope = $request->query('scope', 'all_accessible');

        $complaints = Complaint::query()
            ->with(['citizen', 'department', 'category', 'priority', 'assignedEmployee'])
            ->when($scope === 'assigned_to_me', fn ($query) => $query->where('assigned_employee_id', $employee->id))
            ->when($scope === 'my_department', fn ($query) => $query->where('department_id', $employee->department_id)->whereNull('assigned_employee_id'))
            ->when($scope === 'all_accessible' || ! in_array($scope, ['assigned_to_me', 'my_department'], true), function ($query) use ($employee): void {
                $query->where(function ($accessQuery) use ($employee): void {
                    $accessQuery
                        ->where('assigned_employee_id', $employee->id)
                        ->orWhere(function ($departmentQuery) use ($employee): void {
                            $departmentQuery
                                ->where('department_id', $employee->department_id)
                                ->whereNull('assigned_employee_id');
                        });
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('priority_id'), fn ($query) => $query->where('priority_id', $request->integer('priority_id')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->query('date_to')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->query('search');
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery
                        ->where('complaint_number', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->leftJoin('priorities', 'complaints.priority_id', '=', 'priorities.id')
            ->select('complaints.*')
            ->orderByDesc('priorities.level')
            ->orderByRaw('complaints.due_at IS NULL, complaints.due_at ASC')
            ->latest('complaints.created_at')
            ->paginate($this->perPage($request));

        return $this->successResponse('Complaints retrieved successfully.', [
            'complaints' => ComplaintResource::collection($complaints->getCollection()),
        ], 200, $this->paginationMeta($complaints));
    }

    public function show(Request $request, Complaint $complaint): JsonResponse
    {
        if (! $this->canAccess($complaint, $request->user())) {
            return $this->errorResponse('Forbidden.', [], 403);
        }

        return $this->successResponse('Complaint retrieved successfully.', new ComplaintResource($this->loadComplaint($complaint)));
    }

    public function updateStatus(UpdateComplaintStatusRequest $request, Complaint $complaint): JsonResponse
    {
        if (! $this->canAccess($complaint, $request->user())) {
            return $this->errorResponse('Forbidden.', [], 403);
        }

        $data = $request->validated();

        $complaint = DB::transaction(function () use ($request, $complaint, $data): Complaint {
            $employee = $request->user();
            $complaint->refresh();

            if (! $complaint->assigned_employee_id && (int) $complaint->department_id === (int) $employee->department_id && $data['status'] === 'in_progress') {
                $complaint->assignments()->create([
                    'assigned_by' => $employee->id,
                    'assigned_to' => $employee->id,
                    'department_id' => $complaint->department_id,
                    'note' => 'Employee self-assigned before processing.',
                    'assigned_at' => now(),
                ]);
                $complaint->forceFill(['assigned_employee_id' => $employee->id])->save();

                if (in_array($complaint->status, ['submitted', 'under_review'], true)) {
                    $complaint = $this->statusService->updateStatus($complaint, $employee, 'assigned', 'Employee self-assigned before processing.', true);
                }
            }

            return $this->statusService->updateStatus($complaint, $employee, $data['status'], $data['note'] ?? null);
        });

        return $this->successResponse('Complaint status updated successfully.', new ComplaintResource($this->loadComplaint($complaint)));
    }

    private function canAccess(Complaint $complaint, mixed $employee): bool
    {
        if ((int) $complaint->assigned_employee_id === (int) $employee->id) {
            return true;
        }

        return ! $complaint->assigned_employee_id
            && $employee->department_id
            && (int) $complaint->department_id === (int) $employee->department_id;
    }

    private function loadComplaint(Complaint $complaint): Complaint
    {
        return $complaint->load([
            'citizen',
            'department',
            'category',
            'priority',
            'assignedEmployee',
            'attachments.uploadedBy',
            'statusHistories' => fn ($query) => $query->with('changedBy')->oldest(),
            'assignments' => fn ($query) => $query->with(['assignedBy', 'assignedTo', 'department'])->oldest(),
        ]);
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

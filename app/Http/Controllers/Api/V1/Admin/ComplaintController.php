<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AssignComplaintRequest;
use App\Http\Requests\Api\V1\Admin\ChangeComplaintDepartmentRequest;
use App\Http\Requests\Api\V1\Admin\ChangeComplaintPriorityRequest;
use App\Http\Requests\Api\V1\Admin\UpdateComplaintStatusRequest;
use App\Http\Resources\Api\V1\ComplaintResource;
use App\Http\Responses\ApiResponse;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\User;
use App\Services\Complaints\ComplaintStatusService;
use App\Services\SlaDeadlineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ComplaintController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ComplaintStatusService $statusService,
        private readonly SlaDeadlineService $slaDeadlineService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $complaints = Complaint::query()
            ->with(['citizen', 'department', 'category', 'priority', 'assignedEmployee'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('department_id'), fn ($query) => $query->where('department_id', $request->integer('department_id')))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when($request->filled('priority_id'), fn ($query) => $query->where('priority_id', $request->integer('priority_id')))
            ->when($request->filled('assigned_employee_id'), fn ($query) => $query->where('assigned_employee_id', $request->integer('assigned_employee_id')))
            ->when($request->filled('citizen_id'), fn ($query) => $query->where('citizen_id', $request->integer('citizen_id')))
            ->when($request->has('is_sla_breached'), fn ($query) => $query->where('is_sla_breached', filter_var($request->query('is_sla_breached'), FILTER_VALIDATE_BOOLEAN)))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->query('date_to')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->query('search');
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery
                        ->where('complaint_number', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('citizen', function ($citizenQuery) use ($search): void {
                            $citizenQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            })
            ->latest()
            ->paginate($this->perPage($request));

        return $this->successResponse('Complaints retrieved successfully.', [
            'complaints' => ComplaintResource::collection($complaints->getCollection()),
        ], 200, $this->paginationMeta($complaints));
    }

    public function show(Complaint $complaint): JsonResponse
    {
        return $this->successResponse('Complaint retrieved successfully.', new ComplaintResource($this->loadComplaint($complaint)));
    }

    public function assign(AssignComplaintRequest $request, Complaint $complaint): JsonResponse
    {
        $data = $request->validated();
        $employee = User::query()->findOrFail($data['assigned_employee_id']);

        if ($employee->role !== 'employee') {
            return $this->errorResponse('The selected user must be an employee.', [
                'assigned_employee_id' => ['The selected user must be an employee.'],
            ], 422);
        }

        if ($employee->department_id && $complaint->department_id && (int) $employee->department_id !== (int) $complaint->department_id) {
            return $this->errorResponse('Employee department does not match complaint department.', [
                'assigned_employee_id' => ['Employee department does not match complaint department.'],
            ], 422);
        }

        $complaint = DB::transaction(function () use ($request, $complaint, $employee, $data): Complaint {
            $complaint->assignments()->create([
                'assigned_by' => $request->user()->id,
                'assigned_to' => $employee->id,
                'department_id' => $complaint->department_id,
                'note' => $data['note'] ?? null,
                'assigned_at' => now(),
            ]);

            $complaint->forceFill(['assigned_employee_id' => $employee->id])->save();

            if (in_array($complaint->status, ['submitted', 'under_review'], true)) {
                $complaint = $this->statusService->updateStatus(
                    $complaint,
                    $request->user(),
                    'assigned',
                    $data['note'] ?? 'Complaint assigned to employee.',
                    true,
                );
            } else {
                $this->statusService->addTimelineNote($complaint, $request->user(), $data['note'] ?? 'Complaint assigned to employee.');
            }

            return $complaint->fresh();
        });

        return $this->successResponse('Complaint assigned successfully.', new ComplaintResource($this->loadComplaint($complaint)));
    }

    public function changeDepartment(ChangeComplaintDepartmentRequest $request, Complaint $complaint): JsonResponse
    {
        $data = $request->validated();
        $category = ! empty($data['category_id'])
            ? ComplaintCategory::query()->findOrFail($data['category_id'])
            : null;

        if ($category && (int) $category->department_id !== (int) $data['department_id']) {
            return $this->errorResponse('The selected category does not belong to the selected department.', [
                'category_id' => ['The selected category does not belong to the selected department.'],
            ], 422);
        }

        $complaint->forceFill([
            'department_id' => $data['department_id'],
            'category_id' => $category?->id,
            'assigned_employee_id' => $this->shouldClearAssignedEmployee($complaint, (int) $data['department_id'])
                ? null
                : $complaint->assigned_employee_id,
            'due_at' => $this->slaDeadlineService->calculate((int) $data['department_id'], $category?->id, $complaint->priority_id),
        ])->save();

        $this->statusService->addTimelineNote($complaint, $request->user(), $data['note'] ?? 'Complaint department/category updated.');

        return $this->successResponse('Complaint department updated successfully.', new ComplaintResource($this->loadComplaint($complaint)));
    }

    public function changePriority(ChangeComplaintPriorityRequest $request, Complaint $complaint): JsonResponse
    {
        $data = $request->validated();

        $complaint->forceFill([
            'priority_id' => $data['priority_id'],
            'due_at' => $this->slaDeadlineService->calculate($complaint->department_id, $complaint->category_id, (int) $data['priority_id']),
        ])->save();

        $this->statusService->addTimelineNote($complaint, $request->user(), $data['note'] ?? 'Complaint priority updated.');

        return $this->successResponse('Complaint priority updated successfully.', new ComplaintResource($this->loadComplaint($complaint)));
    }

    public function updateStatus(UpdateComplaintStatusRequest $request, Complaint $complaint): JsonResponse
    {
        $data = $request->validated();
        $complaint = $this->statusService->updateStatus($complaint, $request->user(), $data['status'], $data['note'] ?? null);

        return $this->successResponse('Complaint status updated successfully.', new ComplaintResource($this->loadComplaint($complaint)));
    }

    private function shouldClearAssignedEmployee(Complaint $complaint, int $newDepartmentId): bool
    {
        $employee = $complaint->assignedEmployee;

        return $employee && $employee->department_id && (int) $employee->department_id !== $newDepartmentId;
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

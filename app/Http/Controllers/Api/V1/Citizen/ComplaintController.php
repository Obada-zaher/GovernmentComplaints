<?php

namespace App\Http\Controllers\Api\V1\Citizen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Citizen\AddComplaintAttachmentRequest;
use App\Http\Requests\Api\V1\Citizen\StoreComplaintRequest;
use App\Http\Resources\Api\V1\ComplaintResource;
use App\Http\Responses\ApiResponse;
use App\Models\Complaint;
use App\Services\ComplaintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ComplaintController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ComplaintService $complaintService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $complaints = Complaint::query()
            ->with(['department', 'category', 'priority'])
            ->where('citizen_id', $request->user()->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('department_id'), fn ($query) => $query->where('department_id', $request->integer('department_id')))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when($request->filled('priority_id'), fn ($query) => $query->where('priority_id', $request->integer('priority_id')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->query('date_to')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->query('search');
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('complaint_number', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($this->perPage($request));

        return $this->successResponse('Complaints retrieved successfully.', [
            'complaints' => ComplaintResource::collection($complaints->getCollection()),
        ], 200, $this->paginationMeta($complaints));
    }

    public function store(StoreComplaintRequest $request): JsonResponse
    {
        $complaint = $this->complaintService->create($request->user(), $request->validated());

        return $this->successResponse('Complaint created successfully.', new ComplaintResource($complaint), 201);
    }

    public function show(Request $request, Complaint $complaint): JsonResponse
    {
        if ((int) $complaint->citizen_id !== (int) $request->user()->id) {
            return $this->errorResponse('Forbidden.', [], 403);
        }

        $complaint->load([
            'department',
            'category',
            'priority',
            'assignedEmployee',
            'attachments.uploadedBy',
            'statusHistories' => fn ($query) => $query->with('changedBy')->oldest(),
        ]);

        return $this->successResponse('Complaint retrieved successfully.', new ComplaintResource($complaint));
    }

    public function addAttachments(AddComplaintAttachmentRequest $request, Complaint $complaint): JsonResponse
    {
        if ((int) $complaint->citizen_id !== (int) $request->user()->id) {
            return $this->errorResponse('Forbidden.', [], 403);
        }

        if (in_array($complaint->status, ['closed', 'rejected'], true)) {
            return $this->errorResponse('Cannot add attachments to a closed or rejected complaint.', [], 422);
        }

        $complaint = $this->complaintService->addAttachments(
            $complaint,
            $request->user(),
            $request->validated('attachments'),
        );

        return $this->successResponse('Complaint attachments added successfully.', new ComplaintResource($complaint));
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

<?php

namespace App\Http\Controllers\Api\V1\Citizen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Citizen\OfflineSubmissionFilterRequest;
use App\Http\Requests\Api\V1\Citizen\SyncOfflineComplaintRequest;
use App\Http\Resources\Api\V1\ComplaintResource;
use App\Http\Resources\Api\V1\OfflineSubmissionResource;
use App\Http\Responses\ApiResponse;
use App\Models\OfflineSubmission;
use App\Services\Offline\OfflineComplaintSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class OfflineComplaintSyncController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OfflineComplaintSyncService $syncService) {}

    public function sync(SyncOfflineComplaintRequest $request): JsonResponse
    {
        $result = $this->syncService->sync($request->user(), $request->validated());
        $message = $result['idempotent']
            ? 'Offline complaint was already synced.'
            : 'Offline complaint synced successfully.';

        return $this->successResponse($message, [
            'offline_submission' => new OfflineSubmissionResource($result['offline_submission']->load('syncedComplaint')),
            'complaint' => new ComplaintResource($result['complaint']),
        ], $result['idempotent'] ? 200 : 201, $result['idempotent'] ? ['idempotent' => true] : []);
    }

    public function index(OfflineSubmissionFilterRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $submissions = OfflineSubmission::query()
            ->with('syncedComplaint')
            ->where('citizen_id', $request->user()->id)
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['date_from']), fn ($query) => $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay()))
            ->when(! empty($filters['date_to']), fn ($query) => $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay()))
            ->latest()
            ->paginate($this->perPage($request));

        return $this->successResponse('Offline submissions retrieved successfully.', [
            'offline_submissions' => OfflineSubmissionResource::collection($submissions->getCollection()),
        ], 200, $this->paginationMeta($submissions));
    }

    public function show(Request $request, OfflineSubmission $offlineSubmission): JsonResponse
    {
        if ($request->user()->cannot('view', $offlineSubmission)) {
            return $this->errorResponse('Forbidden.', [], 403);
        }

        return $this->successResponse(
            'Offline submission retrieved successfully.',
            new OfflineSubmissionResource($offlineSubmission->load('syncedComplaint')),
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

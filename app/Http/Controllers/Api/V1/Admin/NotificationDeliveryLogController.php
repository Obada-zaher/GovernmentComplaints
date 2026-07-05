<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\NotificationDeliveryLogFilterRequest;
use App\Http\Resources\Api\V1\NotificationDeliveryLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\NotificationDeliveryLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationDeliveryLogController extends Controller
{
    use ApiResponse;

    public function index(NotificationDeliveryLogFilterRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $logs = NotificationDeliveryLog::query()
            ->with(['user', 'complaint', 'userNotification'])
            ->when(isset($filters['user_id']), fn ($query) => $query->where('user_id', $filters['user_id']))
            ->when(isset($filters['complaint_id']), fn ($query) => $query->where('complaint_id', $filters['complaint_id']))
            ->when(isset($filters['channel']), fn ($query) => $query->where('channel', $filters['channel']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->when(isset($filters['date_from']), fn ($query) => $query->whereDate('created_at', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($query) => $query->whereDate('created_at', '<=', $filters['date_to']))
            ->latest()
            ->paginate($this->perPage($request));

        return $this->successResponse(
            'Notification delivery logs retrieved successfully.',
            ['delivery_logs' => NotificationDeliveryLogResource::collection($logs->getCollection())],
            200,
            $this->paginationMeta($logs),
        );
    }

    public function show(NotificationDeliveryLog $notificationDeliveryLog): JsonResponse
    {
        return $this->successResponse(
            'Notification delivery log retrieved successfully.',
            new NotificationDeliveryLogResource($notificationDeliveryLog->load(['user', 'complaint', 'userNotification'])),
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

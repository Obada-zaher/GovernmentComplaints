<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserNotificationResource;
use App\Http\Responses\ApiResponse;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $notifications = UserNotification::query()
            ->with('complaint')
            ->where('user_id', $request->user()->id)
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->query('type')))
            ->latest()
            ->paginate($this->perPage($request));

        return $this->successResponse('Notifications retrieved successfully.', [
            'notifications' => UserNotificationResource::collection($notifications->getCollection()),
        ], 200, $this->paginationMeta($notifications));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return $this->successResponse('Unread notification count retrieved successfully.', [
            'count' => $count,
        ]);
    }

    public function read(Request $request, UserNotification $notification): JsonResponse
    {
        if (! $this->ownsNotification($request, $notification)) {
            return $this->errorResponse('Forbidden.', [], 403);
        }

        if (! $notification->read_at) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $this->successResponse('Notification marked as read.', new UserNotificationResource($notification->load('complaint')));
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->successResponse('Notifications marked as read.', [
            'updated' => $updated,
        ]);
    }

    public function destroy(Request $request, UserNotification $notification): JsonResponse
    {
        if (! $this->ownsNotification($request, $notification)) {
            return $this->errorResponse('Forbidden.', [], 403);
        }

        $notification->delete();

        return $this->successResponse('Notification deleted successfully.');
    }

    private function ownsNotification(Request $request, UserNotification $notification): bool
    {
        return (int) $notification->user_id === (int) $request->user()->id;
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

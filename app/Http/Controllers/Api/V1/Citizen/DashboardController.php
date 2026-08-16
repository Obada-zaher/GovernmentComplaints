<?php

namespace App\Http\Controllers\Api\V1\Citizen;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Citizen\CitizenDashboardActionRequiredResource;
use App\Http\Resources\Api\V1\Citizen\CitizenDashboardComplaintResource;
use App\Http\Responses\ApiResponse;
use App\Models\Complaint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    /** @var array<int, string> */
    private const ACTIVE_STATUSES = [
        'submitted',
        'under_review',
        'assigned',
        'in_progress',
        'waiting_citizen',
        'escalated',
    ];

    /** @var array<int, string> */
    private const COMPLETED_STATUSES = [
        'resolved',
        'closed',
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $complaints = Complaint::query()->where('citizen_id', $request->user()->id);

        $recentComplaints = (clone $complaints)
            ->with(['department', 'category', 'priority'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $actionRequired = (clone $complaints)
            ->where('status', 'waiting_citizen')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return $this->successResponse('Dashboard retrieved successfully.', [
            'counts' => [
                'total' => (clone $complaints)->count(),
                'active' => (clone $complaints)->whereIn('status', self::ACTIVE_STATUSES)->count(),
                'waiting_citizen' => (clone $complaints)->where('status', 'waiting_citizen')->count(),
                'completed' => (clone $complaints)->whereIn('status', self::COMPLETED_STATUSES)->count(),
            ],
            'recent_complaints' => CitizenDashboardComplaintResource::collection($recentComplaints),
            'action_required' => CitizenDashboardActionRequiredResource::collection($actionRequired),
        ]);
    }
}

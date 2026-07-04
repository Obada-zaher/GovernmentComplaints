<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ComplaintCategoryResource;
use App\Http\Resources\Api\V1\DepartmentResource;
use App\Http\Resources\Api\V1\PriorityResource;
use App\Http\Responses\ApiResponse;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    use ApiResponse;

    public function departments(): JsonResponse
    {
        $departments = Department::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return $this->successResponse('Departments retrieved successfully.', [
            'departments' => DepartmentResource::collection($departments),
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        $categories = ComplaintCategory::query()
            ->with('department')
            ->where('is_active', true)
            ->when($request->filled('department_id'), fn ($query) => $query->where('department_id', $request->integer('department_id')))
            ->when($request->filled('department_code'), function ($query) use ($request): void {
                $query->whereHas('department', fn ($departmentQuery) => $departmentQuery->where('code', $request->query('department_code')));
            })
            ->orderBy('name')
            ->get();

        return $this->successResponse('Categories retrieved successfully.', [
            'categories' => ComplaintCategoryResource::collection($categories),
        ]);
    }

    public function priorities(): JsonResponse
    {
        $priorities = Priority::query()
            ->orderBy('level')
            ->orderBy('name')
            ->get();

        return $this->successResponse('Priorities retrieved successfully.', [
            'priorities' => PriorityResource::collection($priorities),
        ]);
    }

    public function complaintStatuses(): JsonResponse
    {
        return $this->successResponse('Complaint statuses retrieved successfully.', [
            'statuses' => [
                'submitted',
                'under_review',
                'assigned',
                'in_progress',
                'waiting_citizen',
                'resolved',
                'closed',
                'rejected',
                'escalated',
            ],
        ]);
    }
}

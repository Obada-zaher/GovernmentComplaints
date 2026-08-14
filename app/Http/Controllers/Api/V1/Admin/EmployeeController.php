<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ListEmployeesRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class EmployeeController extends Controller
{
    use ApiResponse;

    public function index(ListEmployeesRequest $request): JsonResponse
    {
        $employees = User::query()
            ->with('department')
            ->where('role', 'employee')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->trim()->value();

                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('department_id'), fn ($query) => $query->where('department_id', $request->integer('department_id')))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return $this->successResponse('Employees retrieved successfully.', [
            'employees' => UserResource::collection($employees->getCollection()),
        ], 200, $this->paginationMeta($employees));
    }

    private function perPage(ListEmployeesRequest $request): int
    {
        return min(max($request->integer('per_page', 15), 1), 100);
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

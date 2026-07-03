<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreDepartmentRequest;
use App\Http\Requests\Api\V1\Admin\UpdateDepartmentRequest;
use App\Http\Resources\Api\V1\DepartmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class DepartmentController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $departments = Department::query()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->query('search');
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $this->booleanFilter($request->query('is_active'))))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->successResponse('Departments retrieved successfully.', [
            'departments' => DepartmentResource::collection($departments->getCollection()),
        ], 200, $this->paginationMeta($departments));
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = Department::query()->create($request->validated());

        return $this->successResponse('Department created successfully.', [
            'department' => new DepartmentResource($department),
        ], 201);
    }

    public function show(Department $department): JsonResponse
    {
        return $this->successResponse('Department retrieved successfully.', [
            'department' => new DepartmentResource($department),
        ]);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $department->update($request->validated());

        return $this->successResponse('Department updated successfully.', [
            'department' => new DepartmentResource($department->fresh()),
        ]);
    }

    public function destroy(Department $department): JsonResponse
    {
        $department->delete();

        return $this->successResponse('Department deleted successfully.');
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 15), 1), 100);
    }

    private function booleanFilter(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string, int>
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

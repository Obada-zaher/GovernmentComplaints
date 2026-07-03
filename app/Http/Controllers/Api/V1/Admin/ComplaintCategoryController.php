<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreComplaintCategoryRequest;
use App\Http\Requests\Api\V1\Admin\UpdateComplaintCategoryRequest;
use App\Http\Resources\Api\V1\ComplaintCategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\ComplaintCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ComplaintCategoryController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $categories = ComplaintCategory::query()
            ->with('department')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->query('search');
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('department_id'), fn ($query) => $query->where('department_id', $request->integer('department_id')))
            ->when($request->filled('department_code'), function ($query) use ($request): void {
                $query->whereHas('department', fn ($departmentQuery) => $departmentQuery->where('code', $request->query('department_code')));
            })
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $this->booleanFilter($request->query('is_active'))))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->successResponse('Categories retrieved successfully.', [
            'categories' => ComplaintCategoryResource::collection($categories->getCollection()),
        ], 200, $this->paginationMeta($categories));
    }

    public function store(StoreComplaintCategoryRequest $request): JsonResponse
    {
        $category = ComplaintCategory::query()->create($request->validated())->load('department');

        return $this->successResponse('Category created successfully.', [
            'category' => new ComplaintCategoryResource($category),
        ], 201);
    }

    public function show(ComplaintCategory $category): JsonResponse
    {
        return $this->successResponse('Category retrieved successfully.', [
            'category' => new ComplaintCategoryResource($category->load('department')),
        ]);
    }

    public function update(UpdateComplaintCategoryRequest $request, ComplaintCategory $category): JsonResponse
    {
        $category->update($request->validated());

        return $this->successResponse('Category updated successfully.', [
            'category' => new ComplaintCategoryResource($category->fresh('department')),
        ]);
    }

    public function destroy(ComplaintCategory $category): JsonResponse
    {
        $category->delete();

        return $this->successResponse('Category deleted successfully.');
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

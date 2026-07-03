<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StorePriorityRequest;
use App\Http\Requests\Api\V1\Admin\UpdatePriorityRequest;
use App\Http\Resources\Api\V1\PriorityResource;
use App\Http\Responses\ApiResponse;
use App\Models\Priority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class PriorityController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $priorities = Priority::query()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->query('search');
                $query->where(function ($searchQuery) use ($search): void {
                    $searchQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->orderBy('level')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->successResponse('Priorities retrieved successfully.', [
            'priorities' => PriorityResource::collection($priorities->getCollection()),
        ], 200, $this->paginationMeta($priorities));
    }

    public function store(StorePriorityRequest $request): JsonResponse
    {
        $priority = Priority::query()->create($request->validated());

        return $this->successResponse('Priority created successfully.', [
            'priority' => new PriorityResource($priority),
        ], 201);
    }

    public function show(Priority $priority): JsonResponse
    {
        return $this->successResponse('Priority retrieved successfully.', [
            'priority' => new PriorityResource($priority),
        ]);
    }

    public function update(UpdatePriorityRequest $request, Priority $priority): JsonResponse
    {
        $priority->update($request->validated());

        return $this->successResponse('Priority updated successfully.', [
            'priority' => new PriorityResource($priority->fresh()),
        ]);
    }

    public function destroy(Priority $priority): JsonResponse
    {
        $priority->delete();

        return $this->successResponse('Priority deleted successfully.');
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 15), 1), 100);
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

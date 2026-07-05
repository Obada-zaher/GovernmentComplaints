<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreClassificationRuleRequest;
use App\Http\Requests\Api\V1\Admin\UpdateClassificationRuleRequest;
use App\Http\Resources\Api\V1\ClassificationRuleResource;
use App\Http\Responses\ApiResponse;
use App\Models\ComplaintCategory;
use App\Models\ComplaintClassificationRule;
use App\Services\Classification\ComplaintClassificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ClassificationRuleController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ComplaintClassificationService $classificationService) {}

    public function index(Request $request): JsonResponse
    {
        $rules = ComplaintClassificationRule::query()
            ->with(['department', 'category'])
            ->when($request->filled('department_id'), fn ($query) => $query->where('department_id', $request->integer('department_id')))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when($request->filled('keyword'), fn ($query) => $query->where('keyword', 'like', '%'.$request->query('keyword').'%'))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN)))
            ->latest()
            ->paginate($this->perPage($request));

        return $this->successResponse('Classification rules retrieved successfully.', [
            'classification_rules' => ClassificationRuleResource::collection($rules->getCollection()),
        ], 200, $this->paginationMeta($rules));
    }

    public function store(StoreClassificationRuleRequest $request): JsonResponse
    {
        $data = $this->normalizeRuleData($request->validated());

        if (isset($data['error'])) {
            return $this->errorResponse($data['message'], $data['errors'], 422);
        }

        $rule = ComplaintClassificationRule::query()->create($data);

        return $this->successResponse(
            'Classification rule created successfully.',
            new ClassificationRuleResource($rule->load(['department', 'category'])),
            201,
        );
    }

    public function show(ComplaintClassificationRule $classificationRule): JsonResponse
    {
        return $this->successResponse(
            'Classification rule retrieved successfully.',
            new ClassificationRuleResource($classificationRule->load(['department', 'category'])),
        );
    }

    public function update(UpdateClassificationRuleRequest $request, ComplaintClassificationRule $classificationRule): JsonResponse
    {
        $data = array_merge($classificationRule->only([
            'department_id',
            'category_id',
            'keyword',
            'weight',
            'is_active',
            'language',
            'notes',
        ]), $request->validated());

        $data = $this->normalizeRuleData($data);

        if (isset($data['error'])) {
            return $this->errorResponse($data['message'], $data['errors'], 422);
        }

        $classificationRule->update($data);

        return $this->successResponse(
            'Classification rule updated successfully.',
            new ClassificationRuleResource($classificationRule->fresh(['department', 'category'])),
        );
    }

    public function destroy(ComplaintClassificationRule $classificationRule): JsonResponse
    {
        $classificationRule->delete();

        return $this->successResponse('Classification rule deleted successfully.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeRuleData(array $data): array
    {
        $category = ! empty($data['category_id'])
            ? ComplaintCategory::query()->find((int) $data['category_id'])
            : null;

        if ($category && empty($data['department_id'])) {
            $data['department_id'] = $category->department_id;
        }

        if ($category && ! empty($data['department_id']) && (int) $category->department_id !== (int) $data['department_id']) {
            return [
                'error' => true,
                'message' => 'The selected category does not belong to the selected department.',
                'errors' => [
                    'category_id' => ['The selected category does not belong to the selected department.'],
                ],
            ];
        }

        $data['is_active'] = $data['is_active'] ?? true;
        $data['language'] = $data['language'] ?? 'mixed';
        $data['normalized_keyword'] = $this->classificationService->normalize($data['keyword']);

        return $data;
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

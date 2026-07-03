<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreSlaRuleRequest;
use App\Http\Requests\Api\V1\Admin\UpdateSlaRuleRequest;
use App\Http\Resources\Api\V1\SlaRuleResource;
use App\Http\Responses\ApiResponse;
use App\Models\SlaRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class SlaRuleController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $slaRules = SlaRule::query()
            ->with(['department', 'category', 'priority'])
            ->when($request->filled('department_id'), fn ($query) => $query->where('department_id', $request->integer('department_id')))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when($request->filled('priority_id'), fn ($query) => $query->where('priority_id', $request->integer('priority_id')))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $this->booleanFilter($request->query('is_active'))))
            ->latest()
            ->paginate($this->perPage($request));

        return $this->successResponse('SLA rules retrieved successfully.', [
            'sla_rules' => SlaRuleResource::collection($slaRules->getCollection()),
        ], 200, $this->paginationMeta($slaRules));
    }

    public function store(StoreSlaRuleRequest $request): JsonResponse
    {
        $slaRule = SlaRule::query()->create($request->validated())->load(['department', 'category', 'priority']);

        return $this->successResponse('SLA rule created successfully.', [
            'sla_rule' => new SlaRuleResource($slaRule),
        ], 201);
    }

    public function show(SlaRule $slaRule): JsonResponse
    {
        return $this->successResponse('SLA rule retrieved successfully.', [
            'sla_rule' => new SlaRuleResource($slaRule->load(['department', 'category', 'priority'])),
        ]);
    }

    public function update(UpdateSlaRuleRequest $request, SlaRule $slaRule): JsonResponse
    {
        $slaRule->update($request->validated());

        return $this->successResponse('SLA rule updated successfully.', [
            'sla_rule' => new SlaRuleResource($slaRule->fresh(['department', 'category', 'priority'])),
        ]);
    }

    public function destroy(SlaRule $slaRule): JsonResponse
    {
        $slaRule->delete();

        return $this->successResponse('SLA rule deleted successfully.');
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

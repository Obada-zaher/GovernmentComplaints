<?php

namespace App\Http\Controllers\Api\V1\Classification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Classification\PreviewComplaintClassificationRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Classification\ComplaintClassificationService;
use Illuminate\Http\JsonResponse;

class ComplaintClassificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ComplaintClassificationService $classificationService) {}

    public function preview(PreviewComplaintClassificationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->classificationService->classify($data['title'], $data['description']);
        $this->classificationService->log($data['title'], $data['description'], $result);

        return $this->successResponse(
            'Complaint classification completed successfully.',
            $this->publicResult($result),
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function publicResult(array $result): array
    {
        unset($result['scores'], $result['used_rules']);

        return $result;
    }
}

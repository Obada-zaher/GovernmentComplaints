<?php

namespace App\Http\Responses;

use App\Support\LocalizedText;
use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function successResponse(string $message, mixed $data = [], int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => LocalizedText::resolve($message),
            'data' => $data,
            'meta' => $meta,
        ], $status);
    }

    protected function errorResponse(string $message, array $errors = [], int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => LocalizedText::resolve($message),
            'errors' => LocalizedText::errors($errors),
        ], $status);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDeviceTokenRequest;
use App\Http\Resources\Api\V1\UserDeviceTokenResource;
use App\Http\Responses\ApiResponse;
use App\Models\UserDeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserDeviceTokenController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $tokens = UserDeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->latest()
            ->get();

        return $this->successResponse('Device tokens retrieved successfully.', [
            'device_tokens' => UserDeviceTokenResource::collection($tokens),
        ]);
    }

    public function store(StoreDeviceTokenRequest $request): JsonResponse
    {
        $data = $request->validated();

        $token = UserDeviceToken::withTrashed()
            ->where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->first();

        if ($token) {
            $token->restore();
            $token->forceFill([
                'platform' => $data['platform'],
                'device_name' => $data['device_name'] ?? $token->device_name,
                'app_version' => $data['app_version'] ?? $token->app_version,
                'last_used_at' => now(),
                'is_active' => true,
            ])->save();
        } else {
            $token = UserDeviceToken::query()->create([
                'user_id' => $request->user()->id,
                'token' => $data['token'],
                'platform' => $data['platform'],
                'device_name' => $data['device_name'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'last_used_at' => now(),
                'is_active' => true,
            ]);
        }

        return $this->successResponse('Device token registered successfully.', new UserDeviceTokenResource($token), 201);
    }

    public function destroy(Request $request, UserDeviceToken $deviceToken): JsonResponse
    {
        if ((int) $deviceToken->user_id !== (int) $request->user()->id) {
            return $this->errorResponse('Forbidden.', [], 403);
        }

        $deviceToken->forceFill(['is_active' => false])->save();
        $deviceToken->delete();

        return $this->successResponse('Device token deleted successfully.');
    }
}

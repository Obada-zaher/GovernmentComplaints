<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateNotificationPreferenceRequest;
use App\Http\Resources\Api\V1\NotificationPreferenceResource;
use App\Http\Responses\ApiResponse;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        return $this->successResponse(
            'Notification preferences retrieved successfully.',
            new NotificationPreferenceResource($this->preferencesFor($request->user()->id)),
        );
    }

    public function update(UpdateNotificationPreferenceRequest $request): JsonResponse
    {
        $preferences = $this->preferencesFor($request->user()->id);
        $preferences->fill($request->validated());
        $preferences->database_enabled = true;
        $preferences->save();

        return $this->successResponse(
            'Notification preferences updated successfully.',
            new NotificationPreferenceResource($preferences),
        );
    }

    private function preferencesFor(int $userId): NotificationPreference
    {
        return NotificationPreference::query()->firstOrCreate(
            ['user_id' => $userId],
            NotificationPreference::defaults(),
        );
    }
}

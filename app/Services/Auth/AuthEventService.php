<?php

namespace App\Services\Auth;

use App\Models\AuthEvent;
use App\Models\User;
use Illuminate\Http\Request;

class AuthEventService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(string $event, ?User $user = null, ?Request $request = null, array $metadata = []): void
    {
        AuthEvent::query()->create([
            'user_id' => $user?->id,
            'event' => $event,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $this->safeMetadata($metadata),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function safeMetadata(array $metadata): array
    {
        unset($metadata['password'], $metadata['otp'], $metadata['token'], $metadata['code']);

        return $metadata;
    }
}

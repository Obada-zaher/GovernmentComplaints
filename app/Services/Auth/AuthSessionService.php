<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserDeviceToken;
use Illuminate\Support\Facades\DB;

class AuthSessionService
{
    public function logoutAll(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $this->deactivateAndDeleteDeviceTokens($user);
        });
    }

    protected function deactivateAndDeleteDeviceTokens(User $user): void
    {
        $user->deviceTokens()->each(function (UserDeviceToken $deviceToken): void {
            $deviceToken->forceFill(['is_active' => false])->save();
            $deviceToken->delete();
        });
    }
}

<?php

namespace App\Policies;

use App\Models\OfflineSubmission;
use App\Models\User;

class OfflineSubmissionPolicy
{
    public function view(User $user, OfflineSubmission $offlineSubmission): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return $user->role === 'citizen'
            && (int) $offlineSubmission->citizen_id === (int) $user->id;
    }
}

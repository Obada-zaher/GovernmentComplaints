<?php

namespace App\Services\Sla;

use App\Models\Complaint;

class SlaPauseService
{
    public function pause(Complaint $complaint): bool
    {
        if (! $complaint->due_at || $complaint->is_sla_breached) {
            return false;
        }

        if ($complaint->due_at->lte(now())) {
            $complaint->forceFill(['is_sla_breached' => true])->save();

            return true;
        }

        $complaint->forceFill(['sla_paused_at' => now()])->save();

        return false;
    }

    public function resume(Complaint $complaint, bool $extendDeadline): void
    {
        if (! $complaint->sla_paused_at) {
            return;
        }

        $pausedSeconds = max(0, $complaint->sla_paused_at->diffInSeconds(now()));
        $attributes = [
            'sla_paused_at' => null,
            'sla_total_paused_seconds' => (int) $complaint->sla_total_paused_seconds + $pausedSeconds,
        ];

        if ($extendDeadline && $complaint->due_at && ! $complaint->is_sla_breached) {
            $attributes['due_at'] = $complaint->due_at->copy()->addSeconds($pausedSeconds);
        }

        $complaint->forceFill($attributes)->save();
    }
}

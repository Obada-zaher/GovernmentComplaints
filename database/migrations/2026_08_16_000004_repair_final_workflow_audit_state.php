<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('complaints')
            ->select(['id', 'status', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(100, function ($complaints) use ($now): void {
                foreach ($complaints as $complaint) {
                    $transitionedAt = DB::table('complaint_status_histories')
                        ->where('complaint_id', $complaint->id)
                        ->where('to_status', $complaint->status)
                        ->whereNotNull('from_status')
                        ->whereColumn('from_status', '<>', 'to_status')
                        ->orderByDesc('created_at')
                        ->value('created_at');

                    $relevantHistoryAt = $transitionedAt ?: DB::table('complaint_status_histories')
                        ->where('complaint_id', $complaint->id)
                        ->where('to_status', $complaint->status)
                        ->orderBy('created_at')
                        ->value('created_at');

                    $candidate = $relevantHistoryAt ?: $complaint->created_at ?: $complaint->updated_at ?: $now;
                    $statusEnteredAt = Carbon::parse($candidate);

                    if ($statusEnteredAt->greaterThan($now)) {
                        $statusEnteredAt = $now;
                    }

                    DB::table('complaints')
                        ->where('id', $complaint->id)
                        ->update(['status_entered_at' => $statusEnteredAt]);
                }
            });
    }

    public function down(): void
    {
        // The repair is intentionally non-destructive: preserve corrected clocks on rollback.
    }
};

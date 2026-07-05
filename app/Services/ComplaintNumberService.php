<?php

namespace App\Services;

use App\Models\Complaint;

class ComplaintNumberService
{
    public function generate(): string
    {
        $year = now()->format('Y');
        $prefix = "GCMS-{$year}-";

        $latestNumber = Complaint::query()
            ->where('complaint_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('complaint_number')
            ->value('complaint_number');

        $sequence = $latestNumber
            ? ((int) substr($latestNumber, -6)) + 1
            : 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}

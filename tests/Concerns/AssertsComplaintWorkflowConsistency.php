<?php

namespace Tests\Concerns;

use App\Models\Complaint;

trait AssertsComplaintWorkflowConsistency
{
    protected function assertComplaintWorkflowIsConsistent(Complaint $complaint): void
    {
        $complaint->refresh();
        $activeRequests = $complaint->informationRequests()->whereIn('status', ['pending', 'responded'])->get();

        $this->assertLessThanOrEqual(1, $activeRequests->count());

        if (in_array($complaint->status, ['assigned', 'in_progress', 'waiting_citizen'], true)) {
            $employee = $complaint->assignedEmployee;
            $this->assertNotNull($employee);
            $this->assertSame('employee', $employee->role);
            $this->assertTrue($employee->is_active);
            $this->assertSame($complaint->department_id, $employee->department_id);
        }

        if ($complaint->status === 'waiting_citizen') {
            $this->assertCount(1, $activeRequests);

            if ($complaint->due_at && ! $complaint->is_sla_breached) {
                $this->assertNotNull($complaint->sla_paused_at);
            }
        }

        if (in_array($complaint->status, ['resolved', 'closed', 'rejected'], true)) {
            $this->assertNull($complaint->sla_paused_at);
        }

        $complaint->informationRequests()->where('status', 'responded')->each(function ($request): void {
            $this->assertNotNull($request->responded_at);
        });
        $complaint->informationRequests()->where('status', 'completed')->each(function ($request): void {
            $this->assertNotNull($request->completed_at);
        });
    }
}

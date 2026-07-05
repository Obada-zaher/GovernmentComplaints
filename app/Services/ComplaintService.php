<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Priority;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Sla\SlaDeadlineService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComplaintService
{
    public function __construct(
        private readonly ComplaintNumberService $complaintNumberService,
        private readonly ComplaintAttachmentService $attachmentService,
        private readonly SlaDeadlineService $slaDeadlineService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $citizen, array $data): Complaint
    {
        return DB::transaction(function () use ($citizen, $data): Complaint {
            $category = $this->categoryFromData($data);
            $departmentId = $this->resolveDepartmentId($data, $category);
            $priorityId = $this->resolvePriorityId($data);

            $complaint = Complaint::query()->create([
                'complaint_number' => $this->complaintNumberService->generate(),
                'citizen_id' => $citizen->id,
                'department_id' => $departmentId,
                'category_id' => $category?->id,
                'priority_id' => $priorityId,
                'title' => $data['title'],
                'description' => $data['description'],
                'status' => 'submitted',
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'address' => $data['address'] ?? null,
                'source' => $data['source'] ?? 'web',
                'due_at' => $this->slaDeadlineService->calculate($departmentId, $category?->id, $priorityId),
            ]);

            $complaint->statusHistories()->create([
                'changed_by' => $citizen->id,
                'from_status' => null,
                'to_status' => 'submitted',
                'note' => 'Complaint submitted by citizen',
            ]);

            $this->attachmentService->storeMany($complaint, $citizen, $data['attachments'] ?? []);

            $this->notificationService->notifyAdmins(
                NotificationService::TYPE_COMPLAINT_CREATED,
                $complaint,
                'New complaint submitted',
                "Complaint {$complaint->complaint_number} was submitted by a citizen.",
            );

            $this->notificationService->notifyDepartmentEmployees(
                $departmentId,
                NotificationService::TYPE_COMPLAINT_CREATED,
                $complaint,
                'New complaint in your department',
                "Complaint {$complaint->complaint_number} is available for department review.",
            );

            return $complaint->fresh([
                'department',
                'category',
                'priority',
                'assignedEmployee',
                'attachments',
                'statusHistories.changedBy',
            ]);
        });
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function addAttachments(Complaint $complaint, User $citizen, array $files): Complaint
    {
        return DB::transaction(function () use ($complaint, $citizen, $files): Complaint {
            $this->attachmentService->storeMany($complaint, $citizen, $files);

            $complaint->statusHistories()->create([
                'changed_by' => $citizen->id,
                'from_status' => $complaint->status,
                'to_status' => $complaint->status,
                'note' => 'Citizen added attachments',
            ]);

            return $complaint->fresh([
                'department',
                'category',
                'priority',
                'assignedEmployee',
                'attachments',
                'statusHistories.changedBy',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function categoryFromData(array $data): ?ComplaintCategory
    {
        if (empty($data['category_id'])) {
            return null;
        }

        return ComplaintCategory::query()->find((int) $data['category_id']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveDepartmentId(array $data, ?ComplaintCategory $category): ?int
    {
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : null;

        if ($category && ! $departmentId) {
            return $category->department_id;
        }

        if ($category && $departmentId && $category->department_id !== $departmentId) {
            throw ValidationException::withMessages([
                'category_id' => ['The selected category does not belong to the selected department.'],
            ]);
        }

        return $departmentId;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolvePriorityId(array $data): ?int
    {
        if (! empty($data['priority_id'])) {
            return (int) $data['priority_id'];
        }

        return Priority::query()->where('code', 'medium')->value('id');
    }
}

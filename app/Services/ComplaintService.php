<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\User;
use App\Services\Classification\ComplaintClassificationService;
use App\Services\Complaints\ComplaintInformationRequestService;
use App\Services\Notifications\NotificationService;
use App\Services\Sla\SlaDeadlineService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ComplaintService
{
    public function __construct(
        private readonly ComplaintNumberService $complaintNumberService,
        private readonly ComplaintAttachmentService $attachmentService,
        private readonly SlaDeadlineService $slaDeadlineService,
        private readonly NotificationService $notificationService,
        private readonly ComplaintClassificationService $classificationService,
        private readonly ComplaintInformationRequestService $informationRequestService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $citizen, array $data): Complaint
    {
        $storedFiles = [];

        try {
            return DB::transaction(function () use ($citizen, $data, &$storedFiles): Complaint {
                $classification = $this->classificationService->classify($data['title'], $data['description']);
                $classificationAutoAssigned = false;
                [$data, $classificationAutoAssigned] = $this->applyClassification($data, $classification);
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
                    'status_entered_at' => now(),
                    'assigned_employee_id' => null,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'address' => $data['address'] ?? null,
                    'source' => $data['source'] ?? 'web',
                    'client_uuid' => $data['client_uuid'] ?? null,
                    'classification_confidence' => $this->normalizeClassificationConfidence(
                        $classification['confidence'] ?? 0,
                    ),
                    'classification_auto_assigned' => $classificationAutoAssigned,
                    'due_at' => $this->slaDeadlineService->calculate($departmentId, $category?->id, $priorityId),
                ]);

                $this->classificationService->log(
                    $data['title'],
                    $data['description'],
                    $classification,
                    $complaint,
                    $classificationAutoAssigned,
                );

                $complaint->statusHistories()->create([
                    'changed_by' => $citizen->id,
                    'from_status' => null,
                    'to_status' => 'submitted',
                    'note' => 'Complaint submitted by citizen',
                ]);

                $storedFiles = $this->attachmentService->storeMany($complaint, $citizen, $data['attachments'] ?? []);

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

                $complaint = $complaint->fresh([
                    'department',
                    'category',
                    'priority',
                    'assignedEmployee',
                    'attachments',
                    'statusHistories.changedBy',
                ]);

                return $complaint;
            });
        } catch (Throwable $exception) {
            $this->attachmentService->deleteStoredFiles($storedFiles);

            throw $exception;
        }
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function addAttachments(Complaint $complaint, User $citizen, array $files): Complaint
    {
        $storedFiles = [];

        try {
            [$complaint, $firstInformationResponse] = DB::transaction(function () use ($complaint, $citizen, $files, &$storedFiles): array {
                $complaint = Complaint::query()->lockForUpdate()->findOrFail($complaint->id);

                if ((int) $complaint->citizen_id !== (int) $citizen->id) {
                    throw ValidationException::withMessages([
                        'complaint' => ['You cannot add attachments to this complaint.'],
                    ]);
                }

                $storedFiles = $this->attachmentService->storeMany($complaint, $citizen, $files);

                $firstInformationResponse = $complaint->status === 'waiting_citizen'
                    ? $this->informationRequestService->markCitizenResponse($complaint)
                    : false;

                $complaint->statusHistories()->create([
                    'changed_by' => $citizen->id,
                    'from_status' => $complaint->status,
                    'to_status' => $complaint->status,
                    'note' => 'Citizen added attachments',
                ]);

                return [$complaint->fresh([
                    'department',
                    'category',
                    'priority',
                    'assignedEmployee',
                    'attachments',
                    'statusHistories.changedBy',
                ]), $firstInformationResponse];
            });
        } catch (Throwable $exception) {
            $this->attachmentService->deleteStoredFiles($storedFiles);

            throw $exception;
        }

        if ($firstInformationResponse) {
            $this->notificationService->notifyUser(
                $complaint->assignedEmployee,
                NotificationService::TYPE_COMPLAINT_STATUS_UPDATED,
                $complaint,
                'Citizen provided additional information',
                "The citizen provided the requested information for complaint {$complaint->complaint_number}.",
            );
        }

        return $complaint;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function categoryFromData(array $data): ?ComplaintCategory
    {
        if (empty($data['category_id'])) {
            return null;
        }

        $category = ComplaintCategory::query()
            ->whereKey((int) $data['category_id'])
            ->where('is_active', true)
            ->whereHas('department', fn ($query) => $query->where('is_active', true))
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'category_id' => ['The selected category is not available.'],
            ]);
        }

        return $category;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveDepartmentId(array $data, ?ComplaintCategory $category): ?int
    {
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : null;

        if ($departmentId && ! $this->usableDepartment($departmentId)) {
            throw ValidationException::withMessages([
                'department_id' => ['The selected department is not available.'],
            ]);
        }

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

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $classification
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function applyClassification(array $data, array $classification): array
    {
        $explicitDepartment = ! empty($data['department_id']);
        $explicitCategory = ! empty($data['category_id']);
        $autoAssigned = false;

        if (($explicitDepartment && $explicitCategory) || ($classification['confidence'] ?? 0) < 60) {
            return [$data, false];
        }

        $predictedDepartmentId = $classification['department']['id'] ?? null;
        $predictedCategoryId = $classification['category']['id'] ?? null;

        $predictedCategory = $predictedCategoryId
            ? ComplaintCategory::query()
                ->whereKey($predictedCategoryId)
                ->where('is_active', true)
                ->whereHas('department', fn ($query) => $query->where('is_active', true))
                ->first()
            : null;

        if (! $explicitDepartment && ! $explicitCategory && $predictedCategory) {
            $data['department_id'] = $predictedCategory->department_id;
            $data['category_id'] = $predictedCategory->id;
            $autoAssigned = true;
        } elseif (! $explicitDepartment && ! $explicitCategory && ! $predictedCategoryId) {
            $predictedDepartment = $predictedDepartmentId ? $this->usableDepartment((int) $predictedDepartmentId) : null;

            if ($predictedDepartment) {
                $data['department_id'] = $predictedDepartment->id;
                $autoAssigned = true;
            }
        } elseif (! $explicitCategory && $predictedCategory
            && (int) $predictedCategory->department_id === (int) $data['department_id']) {
            $data['category_id'] = $predictedCategory->id;
            $autoAssigned = true;
        }

        return [$data, $autoAssigned];
    }

    private function usableDepartment(int $departmentId): ?Department
    {
        return Department::query()
            ->whereKey($departmentId)
            ->where('is_active', true)
            ->first();
    }

    private function normalizeClassificationConfidence(mixed $confidence): float
    {
        return round(max(0, min(100, (float) $confidence)), 2);
    }
}

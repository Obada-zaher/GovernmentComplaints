<?php

namespace Database\Seeders;

use App\Models\ClassificationLog;
use App\Models\Complaint;
use App\Models\ComplaintClassificationRule;
use App\Models\NotificationDeliveryLog;
use App\Models\NotificationPreference;
use App\Models\OfflineSubmission;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationService;
use App\Services\Reports\ReportService;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoOperationalDataSeeder extends Seeder
{
    private const SNAPSHOT_MARKER = 'gcms-demo-operational-v1';

    public function __construct(private readonly ReportService $reportService) {}

    public function run(): void
    {
        DB::transaction(function (): void {
            $complaints = Complaint::query()
                ->with(['citizen', 'department', 'category', 'priority', 'assignedEmployee'])
                ->where('complaint_number', 'like', 'GCMS-DEMO-%')
                ->orderBy('complaint_number')
                ->get();

            if ($complaints->count() !== 50) {
                throw new RuntimeException('Demo operational data requires the 50 controlled GCMS-DEMO complaints.');
            }

            $users = $this->demoUsers();
            $admin = $users->get(DemoUsersSeeder::ADMIN_EMAIL);

            if (! $admin) {
                throw new RuntimeException('The deterministic demo admin must be seeded before operational data.');
            }

            $this->removeControlledData($complaints);
            $this->seedPreferences($users);
            $this->seedNotifications($complaints, $admin);
            $this->seedOfflineSubmissions($complaints);
            $this->seedClassificationLogs($complaints);
            $this->seedReportSnapshots($admin);
        });
    }

    /** @param Collection<int, Complaint> $complaints */
    private function removeControlledData(Collection $complaints): void
    {
        $complaintIds = $complaints->pluck('id');

        NotificationDeliveryLog::query()->whereIn('complaint_id', $complaintIds)->delete();
        UserNotification::query()->whereIn('complaint_id', $complaintIds)->delete();
        ClassificationLog::query()->whereIn('complaint_id', $complaintIds)->delete();
        OfflineSubmission::query()->where('client_uuid', 'like', 'demo-offline-%')->delete();

        ReportSnapshot::query()
            ->get()
            ->filter(fn (ReportSnapshot $snapshot): bool => ($snapshot->filters['demo_seed'] ?? null) === self::SNAPSHOT_MARKER)
            ->each
            ->delete();
    }

    /** @return Collection<string, User> */
    private function demoUsers(): Collection
    {
        $emails = [
            DemoUsersSeeder::ADMIN_EMAIL,
            DemoUsersSeeder::PRIMARY_EMPLOYEE_EMAIL,
            'municipality.operations@gcms.test',
            'electricity.employee@gcms.test',
            'electricity.maintenance@gcms.test',
            'water.employee@gcms.test',
            'water.network@gcms.test',
            'transportation.employee@gcms.test',
            'transportation.operations@gcms.test',
            'health.employee@gcms.test',
            'health.services@gcms.test',
            DemoUsersSeeder::PRIMARY_CITIZEN_EMAIL,
            'citizen.one@gcms.test',
            'citizen.two@gcms.test',
            'citizen.three@gcms.test',
            'citizen.four@gcms.test',
            'citizen.five@gcms.test',
        ];
        $users = User::query()->whereIn('email', $emails)->get()->keyBy('email');

        if ($users->count() !== count($emails)) {
            throw new RuntimeException('All 17 deterministic demo users must exist before operational data is seeded.');
        }

        return $users;
    }

    /** @param Collection<string, User> $users */
    private function seedPreferences(Collection $users): void
    {
        foreach ($users as $user) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id],
                array_merge(NotificationPreference::defaults(), [
                    'email_enabled' => false,
                    'push_enabled' => false,
                    'sms_enabled' => false,
                ]),
            );
        }
    }

    /** @param Collection<int, Complaint> $complaints */
    private function seedNotifications(Collection $complaints, User $admin): void
    {
        foreach ($complaints as $complaint) {
            $this->recordNotification(
                $complaint->citizen,
                $complaint,
                NotificationService::TYPE_COMPLAINT_CREATED,
                'تم استلام الشكوى',
                "تم استلام الشكوى {$complaint->complaint_number} وإحالتها للمراجعة.",
                $complaint->created_at,
            );

            if ($complaint->status !== 'submitted') {
                $type = match ($complaint->status) {
                    'resolved' => NotificationService::TYPE_COMPLAINT_RESOLVED,
                    'closed' => NotificationService::TYPE_COMPLAINT_CLOSED,
                    default => NotificationService::TYPE_COMPLAINT_STATUS_UPDATED,
                };
                $this->recordNotification(
                    $complaint->citizen,
                    $complaint,
                    $type,
                    $this->statusTitle($complaint->status),
                    "تم تحديث حالة الشكوى {$complaint->complaint_number} إلى {$complaint->status}.",
                    $complaint->updated_at,
                );
            }

            if ($complaint->assignedEmployee) {
                $assignedAt = $complaint->assignments()->oldest('assigned_at')->first()?->assigned_at ?? $complaint->updated_at;
                $this->recordNotification(
                    $complaint->citizen,
                    $complaint,
                    NotificationService::TYPE_COMPLAINT_ASSIGNED,
                    'تمت إحالة الشكوى',
                    "تمت إحالة الشكوى {$complaint->complaint_number} إلى الموظف المختص.",
                    $assignedAt,
                );
                $this->recordNotification(
                    $complaint->assignedEmployee,
                    $complaint,
                    NotificationService::TYPE_COMPLAINT_ASSIGNED,
                    'شكوى جديدة مكلّف بها',
                    "تمت إحالة الشكوى {$complaint->complaint_number} إليك للمتابعة.",
                    $assignedAt,
                );
            }

            if ($complaint->is_sla_breached) {
                $this->recordNotification(
                    $admin,
                    $complaint,
                    NotificationService::TYPE_SLA_BREACHED,
                    'تجاوز مهلة SLA',
                    "تجاوزت الشكوى {$complaint->complaint_number} مهلة المعالجة المحددة.",
                    $complaint->due_at,
                );
            }

            if ($complaint->status === 'escalated') {
                $this->recordNotification(
                    $admin,
                    $complaint,
                    NotificationService::TYPE_COMPLAINT_STATUS_UPDATED,
                    'شكوى مصعّدة',
                    "تم تصعيد الشكوى {$complaint->complaint_number} وتحتاج إلى متابعة إدارية.",
                    $complaint->updated_at,
                );
            }
        }
    }

    private function recordNotification(User $user, Complaint $complaint, string $type, string $title, string $body, CarbonInterface $createdAt): void
    {
        $number = $this->complaintNumber($complaint);
        $readAt = $createdAt->lessThan(now()->subDays(14)) || $number % 3 !== 0
            ? $createdAt->addHours(3)
            : null;
        $payload = [
            'complaint_id' => $complaint->id,
            'complaint_number' => $complaint->complaint_number,
            'status' => $complaint->status,
            'url_hint' => "/complaints/{$complaint->id}",
            'seeded' => true,
        ];

        $notification = UserNotification::query()->updateOrCreate(
            ['user_id' => $user->id, 'complaint_id' => $complaint->id, 'type' => $type],
            ['title' => $title, 'body' => $body, 'data' => $payload, 'read_at' => $readAt],
        );
        $notification->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        $this->recordDeliveryLog($user, $notification, $complaint, 'database', 'sent', null, $payload, $createdAt);
        $this->recordDeliveryLog($user, $notification, $complaint, 'push', 'skipped', 'External push delivery is intentionally skipped for demo seed data.', $payload, $createdAt);
    }

    /** @param array<string, mixed> $payload */
    private function recordDeliveryLog(User $user, UserNotification $notification, Complaint $complaint, string $channel, string $status, ?string $error, array $payload, CarbonInterface $at): void
    {
        NotificationDeliveryLog::query()->create([
            'user_id' => $user->id,
            'user_notification_id' => $notification->id,
            'complaint_id' => $complaint->id,
            'channel' => $channel,
            'type' => $notification->type,
            'recipient' => $channel === 'database' ? null : $user->email,
            'status' => $status,
            'provider' => 'demo-seed',
            'provider_message_id' => null,
            'error_message' => $error,
            'payload' => $payload,
            'sent_at' => $status === 'sent' ? $at : null,
            'failed_at' => null,
        ])->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
    }

    /** @param Collection<int, Complaint> $complaints */
    private function seedOfflineSubmissions(Collection $complaints): void
    {
        $offlineComplaints = $complaints->where('source', 'offline_sync');

        foreach ($offlineComplaints as $complaint) {
            OfflineSubmission::query()->updateOrCreate(
                ['client_uuid' => $complaint->client_uuid],
                [
                    'citizen_id' => $complaint->citizen_id,
                    'payload' => $this->offlinePayload($complaint),
                    'status' => 'synced',
                    'synced_complaint_id' => $complaint->id,
                    'error_message' => null,
                    'submitted_offline_at' => $complaint->created_at->subHours(2),
                    'synced_at' => $complaint->created_at->addMinutes(5),
                ],
            );
        }

        $primaryCitizen = $complaints->firstWhere('citizen.email', DemoUsersSeeder::PRIMARY_CITIZEN_EMAIL)?->citizen;
        $secondaryCitizen = $complaints->firstWhere('citizen.email', 'citizen.one@gcms.test')?->citizen;

        foreach ([
            ['demo-offline-pending-001', $primaryCitizen, 'pending', null],
            ['demo-offline-failed-001', $secondaryCitizen, 'failed', 'تعذر مزامنة البلاغ التجريبي بسبب فقدان الاتصال، ويمكن إعادة المحاولة لاحقاً.'],
        ] as [$uuid, $citizen, $status, $error]) {
            if (! $citizen) {
                throw new RuntimeException('A deterministic demo citizen is required for pending and failed offline examples.');
            }

            OfflineSubmission::query()->updateOrCreate(
                ['client_uuid' => $uuid],
                [
                    'citizen_id' => $citizen->id,
                    'payload' => [
                        'client_uuid' => $uuid,
                        'title' => $status === 'pending' ? 'بلاغ غير متصل بانتظار المزامنة' : 'بلاغ غير متصل تعذر رفعه',
                        'description' => 'بيانات تجريبية لعرض حالات المزامنة دون إنشاء شكوى إضافية.',
                        'source' => 'offline_sync',
                        'created_offline_at' => now()->subHours(4)->toISOString(),
                    ],
                    'status' => $status,
                    'synced_complaint_id' => null,
                    'error_message' => $error,
                    'submitted_offline_at' => now()->subHours(4),
                    'synced_at' => null,
                ],
            );
        }
    }

    /** @return array<string, mixed> */
    private function offlinePayload(Complaint $complaint): array
    {
        return [
            'client_uuid' => $complaint->client_uuid,
            'title' => $complaint->title,
            'description' => $complaint->description,
            'department_id' => $complaint->department_id,
            'category_id' => $complaint->category_id,
            'priority_id' => $complaint->priority_id,
            'latitude' => $complaint->latitude,
            'longitude' => $complaint->longitude,
            'address' => $complaint->address,
            'created_offline_at' => $complaint->created_at->subHours(2)->toISOString(),
            'source' => 'offline_sync',
        ];
    }

    /** @param Collection<int, Complaint> $complaints */
    private function seedClassificationLogs(Collection $complaints): void
    {
        foreach ($complaints->where('source', '!=', 'admin') as $complaint) {
            $rule = ComplaintClassificationRule::query()
                ->where('department_id', $complaint->department_id)
                ->where('category_id', $complaint->category_id)
                ->orderBy('id')
                ->first();
            $keyword = $this->classificationKeyword($complaint);
            $score = 9 + ($this->complaintNumber($complaint) % 4);

            ClassificationLog::query()->create([
                'complaint_id' => $complaint->id,
                'title' => $complaint->title,
                'description' => $complaint->description,
                'predicted_department_id' => $complaint->department_id,
                'predicted_category_id' => $complaint->category_id,
                'confidence' => 90 + ($score % 8),
                'scores' => [
                    'departments' => [(string) $complaint->department_id => $score],
                    'categories' => [(string) $complaint->category_id => $score],
                    'total_matched_score' => $score,
                    'winning_score' => $score,
                ],
                'used_rules' => [[
                    'id' => $rule?->id,
                    'keyword' => $keyword,
                    'weight' => $score,
                    'department_id' => $complaint->department_id,
                    'category_id' => $complaint->category_id,
                ]],
                'accepted' => true,
                'created_at' => $complaint->created_at->addMinutes(10),
            ]);
        }
    }

    private function classificationKeyword(Complaint $complaint): string
    {
        return match ($complaint->department?->code) {
            'municipality' => str_contains($complaint->category?->code ?? '', 'waste') ? 'نفايات' : 'طريق',
            'electricity' => 'كهرباء',
            'water' => 'مياه',
            'transportation' => 'مرور',
            'health' => 'صحة',
            default => 'شكوى',
        };
    }

    private function seedReportSnapshots(User $admin): void
    {
        foreach ([
            'overview',
            'complaints_by_status',
            'complaints_by_department',
            'complaints_by_priority',
            'sla_performance',
            'employee_performance',
            'complaint_trends',
        ] as $type) {
            $filters = ['demo_seed' => self::SNAPSHOT_MARKER];

            ReportSnapshot::query()->create([
                'type' => $type,
                'filters' => $filters,
                'data' => $this->reportService->generateByType($type),
                'generated_by' => $admin->id,
                'generated_at' => now(),
            ]);
        }
    }

    private function statusTitle(string $status): string
    {
        return match ($status) {
            'resolved' => 'تمت معالجة الشكوى',
            'closed' => 'تم إغلاق الشكوى',
            'rejected' => 'تم رفض الشكوى بعد المراجعة',
            'escalated' => 'تم تصعيد الشكوى',
            default => 'تم تحديث حالة الشكوى',
        };
    }

    private function complaintNumber(Complaint $complaint): int
    {
        return (int) substr($complaint->complaint_number, -3);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Complaint;
use App\Models\ComplaintAssignment;
use App\Models\ComplaintCategory;
use App\Models\ComplaintInformationRequest;
use App\Models\ComplaintStatusHistory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\User;
use App\Services\Sla\SlaDeadlineService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoComplaintsSeeder extends Seeder
{
    /** @var array<int, int> */
    private const BREACHED_NUMBERS = [3, 4, 6, 9, 14, 16, 24, 27, 29, 36, 44, 47];

    /** @var array<int, int> */
    private const REASSIGNED_NUMBERS = [6, 16, 26, 36, 46];

    public function __construct(private readonly SlaDeadlineService $slaDeadlineService) {}

    public function run(): void
    {
        $admin = User::query()->where('email', DemoUsersSeeder::ADMIN_EMAIL)->firstOrFail();
        $departments = Department::query()->pluck('id', 'code');
        $priorities = Priority::query()->pluck('id', 'code');
        $categories = ComplaintCategory::query()->get()->keyBy('code');
        $citizens = $this->citizens();
        $employees = $this->employees($departments);

        foreach ($this->scenarios() as $scenario) {
            $this->seedScenario($scenario, $admin, $departments, $priorities, $categories, $citizens, $employees);
        }
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  Collection<string, int>  $departments
     * @param  Collection<string, int>  $priorities
     * @param  Collection<string, ComplaintCategory>  $categories
     * @param  Collection<string, User>  $citizens
     * @param  Collection<string, Collection<int, User>>  $employees
     */
    private function seedScenario(
        array $scenario,
        User $admin,
        Collection $departments,
        Collection $priorities,
        Collection $categories,
        Collection $citizens,
        Collection $employees,
    ): void {
        DB::transaction(function () use ($scenario, $admin, $departments, $priorities, $categories, $citizens, $employees): void {
            $number = (int) $scenario['number'];
            $complaintNumber = sprintf('GCMS-DEMO-%03d', $number);
            $category = $categories->get($scenario['category']);
            $departmentId = $departments->get($scenario['department']);
            $priorityId = $priorities->get($scenario['priority']);
            $citizen = $citizens->get($scenario['citizen']);

            if (! $category || ! $departmentId || ! $priorityId || ! $citizen) {
                throw new RuntimeException("Missing reference data required for {$complaintNumber}.");
            }

            $createdAt = $this->createdAt($scenario);
            $resolutionHours = $this->resolutionHours((int) $departmentId, $category->id, (int) $priorityId);
            $dueAt = $createdAt->addHours($resolutionHours);
            $isAssigned = ! in_array($scenario['status'], ['submitted', 'under_review', 'rejected'], true);
            $departmentEmployees = $employees->get($scenario['department']);
            $currentEmployee = $isAssigned
                ? $departmentEmployees->get((int) $scenario['employee_slot'])
                : null;

            if ($isAssigned && ! $currentEmployee) {
                throw new RuntimeException("Missing demo employee required for {$complaintNumber}.");
            }

            $complaint = Complaint::withTrashed()->firstOrNew(['complaint_number' => $complaintNumber]);

            if ($complaint->trashed()) {
                $complaint->restore();
            }

            // Only controlled GCMS-DEMO records are rebuilt. Unrelated records are untouched.
            if ($complaint->exists) {
                $complaint->attachments()->delete();
                $complaint->notifications()->delete();
                $complaint->statusHistories()->delete();
                $complaint->assignments()->delete();
                $complaint->informationRequests()->delete();
            }

            $timeline = $this->timeline($scenario, $createdAt, $resolutionHours, $citizen, $admin, $currentEmployee);
            $pauseSeconds = $this->pausedSeconds($timeline);
            $isWaiting = $scenario['status'] === 'waiting_citizen';
            $lastEvent = $timeline[array_key_last($timeline)];
            $firstResponseAt = count($timeline) > 1 ? $timeline[1]['at'] : null;
            $resolvedEvent = collect($timeline)->firstWhere('to_status', 'resolved');
            $closedEvent = collect($timeline)->firstWhere('to_status', 'closed');

            $complaint->forceFill([
                'citizen_id' => $citizen->id,
                'department_id' => $departmentId,
                'category_id' => $category->id,
                'priority_id' => $priorityId,
                'assigned_employee_id' => $currentEmployee?->id,
                'title' => $scenario['title'],
                'description' => $scenario['description'],
                'status' => $scenario['status'],
                'latitude' => $scenario['latitude'],
                'longitude' => $scenario['longitude'],
                'address' => $scenario['address'],
                'source' => $scenario['source'],
                'client_uuid' => $scenario['source'] === 'offline_sync' ? sprintf('demo-offline-%03d-7b84-4f1c-9a2f', $number) : null,
                'classification_confidence' => $scenario['source'] === 'admin' ? null : 0.70 + (($number % 10) * 0.03),
                'due_at' => $dueAt->addSeconds($pauseSeconds),
                'sla_paused_at' => $isWaiting && ! in_array($number, self::BREACHED_NUMBERS, true) ? $lastEvent['at'] : null,
                'sla_total_paused_seconds' => $pauseSeconds,
                'first_response_at' => $firstResponseAt,
                'resolved_at' => $resolvedEvent['at'] ?? null,
                'closed_at' => $closedEvent['at'] ?? null,
                'is_sla_breached' => in_array($number, self::BREACHED_NUMBERS, true),
                'created_at' => $createdAt,
                'updated_at' => $lastEvent['at'],
                'deleted_at' => null,
            ])->saveQuietly();

            foreach ($timeline as $event) {
                $history = ComplaintStatusHistory::query()->create([
                    'complaint_id' => $complaint->id,
                    'changed_by' => $event['actor']->id,
                    'from_status' => $event['from_status'],
                    'to_status' => $event['to_status'],
                    'note' => $event['note'],
                    'duration_minutes' => $event['duration_minutes'],
                ]);

                $history->forceFill([
                    'created_at' => $event['at'],
                    'updated_at' => $event['at'],
                ])->saveQuietly();
            }

            $this->seedInformationRequests($complaint, $timeline);

            if ($currentEmployee) {
                $this->seedAssignments($complaint, $scenario, $admin, $departmentEmployees, $currentEmployee, $timeline);
            }
        });
    }

    /**
     * @param  array<int, array{from_status: string|null, to_status: string, actor: User, note: string, duration_minutes: int|null, at: CarbonImmutable}>  $timeline
     */
    private function seedInformationRequests(Complaint $complaint, array $timeline): void
    {
        foreach ($timeline as $index => $event) {
            if ($event['to_status'] !== 'waiting_citizen') {
                continue;
            }

            $nextEvent = $timeline[$index + 1] ?? null;
            $completed = $nextEvent && in_array($nextEvent['to_status'], ['in_progress', 'resolved'], true);
            $request = ComplaintInformationRequest::query()->create([
                'complaint_id' => $complaint->id,
                'requested_by' => $event['actor']->id,
                'message' => $event['note'],
                'status' => $completed ? 'completed' : 'pending',
                'requested_at' => $event['at'],
                'responded_at' => $completed ? $nextEvent['at']->subMinute() : null,
                'completed_at' => $completed ? $nextEvent['at'] : null,
            ]);
            $request->forceFill([
                'created_at' => $event['at'],
                'updated_at' => $completed ? $nextEvent['at'] : $event['at'],
            ])->saveQuietly();
        }
    }

    /**
     * @param  array<int, array{from_status: string|null, to_status: string, actor: User, note: string, duration_minutes: int|null, at: CarbonImmutable}>  $timeline
     */
    private function pausedSeconds(array $timeline): int
    {
        $seconds = 0;

        foreach ($timeline as $index => $event) {
            if ($event['to_status'] === 'waiting_citizen' && isset($timeline[$index + 1])) {
                $seconds += $event['at']->diffInSeconds($timeline[$index + 1]['at']);
            }
        }

        return $seconds;
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<int, array{from_status: string|null, to_status: string, actor: User, note: string, duration_minutes: int|null, at: CarbonImmutable}>
     */
    private function timeline(array $scenario, CarbonImmutable $createdAt, int $resolutionHours, User $citizen, User $admin, ?User $employee): array
    {
        $path = match ($scenario['status']) {
            'submitted' => ['submitted'],
            'under_review' => ['submitted', 'under_review'],
            'assigned' => ['submitted', 'under_review', 'assigned'],
            'in_progress' => ['submitted', 'under_review', 'assigned', 'in_progress'],
            'waiting_citizen' => ['submitted', 'under_review', 'assigned', 'in_progress', 'waiting_citizen'],
            'resolved' => (int) $scenario['number'] % 2 === 0
                ? ['submitted', 'under_review', 'assigned', 'in_progress', 'resolved']
                : ['submitted', 'under_review', 'assigned', 'in_progress', 'waiting_citizen', 'in_progress', 'resolved'],
            'closed' => ['submitted', 'under_review', 'assigned', 'in_progress', 'resolved', 'closed'],
            'rejected' => ['submitted', 'under_review', 'rejected'],
            'escalated' => ['submitted', 'under_review', 'assigned', 'in_progress', 'escalated'],
        };

        $targetHours = $this->timelineTargetHours($scenario, $resolutionHours);
        $events = [];

        foreach ($path as $index => $toStatus) {
            $at = $index === 0
                ? $createdAt
                : $createdAt->addMinutes((int) round(($targetHours * 60 * $index) / (count($path) - 1)));
            $previous = $path[$index - 1] ?? null;
            $actor = $index === 0
                ? $citizen
                : (in_array($toStatus, ['under_review', 'assigned', 'rejected'], true) ? $admin : ($employee ?? $admin));

            $events[] = [
                'from_status' => $previous,
                'to_status' => $toStatus,
                'actor' => $actor,
                'note' => $this->timelineNote($toStatus),
                'duration_minutes' => $index === 0 ? null : $events[$index - 1]['at']->diffInMinutes($at),
                'at' => $at,
            ];
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function timelineTargetHours(array $scenario, int $resolutionHours): int
    {
        $number = (int) $scenario['number'];

        if (in_array($number, self::BREACHED_NUMBERS, true) && in_array($scenario['status'], ['resolved', 'closed'], true)) {
            return $resolutionHours + 12;
        }

        if (in_array($scenario['status'], ['resolved', 'closed'], true)) {
            return max(12, min($resolutionHours - 6, (int) floor($resolutionHours * 0.65)));
        }

        return match ($scenario['status']) {
            'submitted' => 0,
            'under_review' => 2,
            'assigned' => 4,
            'in_progress' => 8,
            'waiting_citizen' => 14,
            'rejected' => 5,
            'escalated' => 7,
            default => 0,
        };
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  Collection<int, User>  $departmentEmployees
     * @param  array<int, array{from_status: string|null, to_status: string, actor: User, note: string, duration_minutes: int|null, at: CarbonImmutable}>  $timeline
     */
    private function seedAssignments(Complaint $complaint, array $scenario, User $admin, Collection $departmentEmployees, User $currentEmployee, array $timeline): void
    {
        $assignedAt = collect($timeline)->firstWhere('to_status', 'assigned')['at'];
        $number = (int) $scenario['number'];
        $assignments = [[
            'employee' => $currentEmployee,
            'note' => 'تمت إحالة الشكوى إلى الموظف المختص لمتابعتها.',
            'at' => $assignedAt,
        ]];

        if (in_array($number, self::REASSIGNED_NUMBERS, true)) {
            $initialEmployee = $departmentEmployees->first(fn (User $employee): bool => $employee->id !== $currentEmployee->id);

            $assignments[0] = [
                'employee' => $initialEmployee,
                'note' => 'تمت الإحالة الأولية للموظف المناوب في القسم.',
                'at' => $assignedAt,
            ];
            $assignments[] = [
                'employee' => $currentEmployee,
                'note' => 'أعيدت إحالة الشكوى إلى الموظف الحالي لاستكمال المعالجة.',
                'at' => $assignedAt->addMinutes(45),
            ];
        }

        foreach ($assignments as $assignmentData) {
            $assignment = ComplaintAssignment::query()->create([
                'complaint_id' => $complaint->id,
                'assigned_by' => $admin->id,
                'assigned_to' => $assignmentData['employee']->id,
                'department_id' => $complaint->department_id,
                'note' => $assignmentData['note'],
                'assigned_at' => $assignmentData['at'],
            ]);

            $assignment->forceFill([
                'created_at' => $assignmentData['at'],
                'updated_at' => $assignmentData['at'],
            ])->saveQuietly();
        }
    }

    private function resolutionHours(int $departmentId, int $categoryId, int $priorityId): int
    {
        $rule = $this->slaDeadlineService->findRule($departmentId, $categoryId, $priorityId);

        if (! $rule) {
            throw new RuntimeException('An active SLA rule is required for every demo complaint.');
        }

        return (int) $rule->resolution_time_hours;
    }

    /** @param array<string, mixed> $scenario */
    private function createdAt(array $scenario): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays((int) $scenario['days_ago'])->startOfDay()->addHours(8);
    }

    private function timelineNote(string $status): string
    {
        return match ($status) {
            'submitted' => 'تم تقديم الشكوى من قبل المواطن.',
            'under_review' => 'بدأت المراجعة الإدارية للشكوى وتحديد الجهة المختصة.',
            'assigned' => 'تمت إحالة الشكوى إلى موظف القسم المختص.',
            'in_progress' => 'بدأت أعمال المعالجة الميدانية والمتابعة الفنية.',
            'waiting_citizen' => 'بانتظار معلومات إضافية من المواطن لاستكمال المعالجة.',
            'resolved' => 'تمت معالجة المشكلة وإعادة الخدمة إلى وضعها الطبيعي.',
            'closed' => 'أغلقت الشكوى بعد التحقق من اكتمال المعالجة.',
            'rejected' => 'تم رفض الشكوى بعد المراجعة لعدم انطباق الاختصاص أو لتكرار البلاغ.',
            'escalated' => 'تم تصعيد الشكوى بسبب الحاجة إلى تدخل عاجل أو دعم إضافي.',
        };
    }

    /** @return Collection<string, User> */
    private function citizens(): Collection
    {
        $emails = [
            DemoUsersSeeder::PRIMARY_CITIZEN_EMAIL,
            'citizen.one@gcms.test',
            'citizen.two@gcms.test',
            'citizen.three@gcms.test',
            'citizen.four@gcms.test',
            'citizen.five@gcms.test',
        ];

        $citizens = User::query()->whereIn('email', $emails)->get()->keyBy('email');

        if ($citizens->count() !== count($emails)) {
            throw new RuntimeException('All deterministic demo citizens must be seeded before demo complaints.');
        }

        return $citizens;
    }

    /** @param Collection<string, int> $departments
     * @return Collection<string, Collection<int, User>>
     */
    private function employees(Collection $departments): Collection
    {
        $emailsByDepartment = [
            'municipality' => [DemoUsersSeeder::PRIMARY_EMPLOYEE_EMAIL, 'municipality.operations@gcms.test'],
            'electricity' => ['electricity.employee@gcms.test', 'electricity.maintenance@gcms.test'],
            'water' => ['water.employee@gcms.test', 'water.network@gcms.test'],
            'transportation' => ['transportation.employee@gcms.test', 'transportation.operations@gcms.test'],
            'health' => ['health.employee@gcms.test', 'health.services@gcms.test'],
        ];
        $employees = User::query()->whereIn('email', array_merge(...array_values($emailsByDepartment)))->get()->keyBy('email');

        return collect($emailsByDepartment)
            ->mapWithKeys(function (array $emails, string $departmentCode) use ($departments, $employees): array {
                $departmentEmployees = collect($emails)->map(fn (string $email): ?User => $employees->get($email))->filter()->values();

                if ($departmentEmployees->count() !== 2 || $departmentEmployees->contains(fn (User $employee): bool => (int) $employee->department_id !== (int) $departments->get($departmentCode))) {
                    throw new RuntimeException("Exactly two demo employees are required for {$departmentCode}.");
                }

                return [$departmentCode => $departmentEmployees];
            });
    }

    /** @return array<int, array<string, mixed>> */
    private function scenarios(): array
    {
        $municipality = [
            ['municipality-road-damage', 'حفرة كبيرة قرب مدخل المدرسة', 'توجد حفرة عميقة قرب مدخل المدرسة تسبب خطراً على الطلاب والسيارات خصوصاً عند الازدحام الصباحي.', 'المزة - قرب المدرسة الرسمية'],
            ['municipality-waste-collection', 'تراكم النفايات بجانب الحاويات', 'لم يتم تفريغ الحاويات منذ عدة أيام وانتشرت الروائح والحشرات بجوار المباني السكنية.', 'ركن الدين - شارع المدارس'],
            ['municipality-street-lighting', 'إنارة شارع فرعي متوقفة', 'عدة أعمدة إنارة في الشارع الفرعي لا تعمل ليلاً مما يجعل مرور السكان غير آمن.', 'كفرسوسة - الحي الغربي'],
            ['municipality-road-damage', 'هبوط في الإسفلت بعد أعمال صيانة', 'ظهر هبوط واضح في الإسفلت بعد أعمال تمديد حديثة ويحتاج الموقع إلى تسوية عاجلة.', 'البرامكة - قرب الجامعة'],
            ['municipality-waste-collection', 'حاوية نفايات ممتلئة أمام السوق', 'الحاوية أمام السوق الشعبي ممتلئة منذ الصباح وتعيق حركة المارة وأصحاب المحال.', 'الميدان - السوق الشعبي'],
            ['municipality-street-lighting', 'عمود إنارة مائل في الحديقة', 'عمود الإنارة داخل الحديقة مائل بشكل ملحوظ ويحتاج إلى تثبيت وفحص التوصيلات.', 'مشروع دمر - الحديقة المركزية'],
            ['municipality-road-damage', 'تشققات واسعة في الطريق الرئيسي', 'تشققات متتابعة في الطريق الرئيسي تسبب اهتزاز المركبات وتزداد سوءاً بعد الأمطار.', 'شارع بغداد - التقاطع الشرقي'],
            ['municipality-waste-collection', 'مخلفات بناء متروكة على الرصيف', 'توجد أكياس ومخلفات بناء على الرصيف منذ أسبوع وتمنع استخدامه من قبل المشاة.', 'باب توما - الزقاق الخلفي'],
            ['municipality-street-lighting', 'مصباح شارع يومض باستمرار', 'مصباح الإنارة القريب من موقف الحافلات يومض بشكل متكرر وقد يتوقف تماماً مساءً.', 'ساحة الأمويين - الممر الجانبي'],
            ['municipality-road-damage', 'غطاء منهل منخفض في المسار', 'غطاء منهل منخفض عن مستوى الطريق ويشكل خطراً على الدراجات والسيارات الصغيرة.', 'جرمانا - شارع البلدية'],
        ];
        $electricity = [
            ['electricity-power-outage', 'انقطاع كهرباء متكرر في الحي', 'ينقطع التيار عدة مرات يومياً عن الأبنية السكنية مع عودة غير مستقرة للكهرباء.', 'المزة - حي الفيلات'],
            ['electricity-dangerous-electrical-wire', 'أسلاك مكشوفة قرب مدرسة', 'توجد أسلاك كهربائية مكشوفة على عمود قريب من المدرسة ويجب تأمينها قبل دوام الطلاب.', 'ركن الدين - قرب المدرسة'],
            ['electricity-power-outage', 'ضعف شديد في الجهد الكهربائي', 'الجهد الكهربائي منخفض منذ يومين ويتسبب بتعطل الأجهزة المنزلية وإضاءة ضعيفة.', 'كفرسوسة - شارع التنظيم'],
            ['electricity-dangerous-electrical-wire', 'كابل متدلٍ فوق الرصيف', 'كابل كهربائي متدلٍ فوق الرصيف بعد الرياح ويقترب من مسار المشاة.', 'البرامكة - شارع المشافي'],
            ['electricity-power-outage', 'انقطاع التيار عن مبنى سكني', 'لا تصل الكهرباء إلى المبنى منذ ساعات بينما الأبنية المجاورة لديها تغذية مستقرة.', 'الميدان - حي القاعة'],
            ['electricity-dangerous-electrical-wire', 'صندوق كهرباء مفتوح', 'غطاء صندوق الكهرباء في الشارع مفتوح والأسلاك الداخلية ظاهرة للأطفال والمارة.', 'مشروع دمر - المدخل الشمالي'],
            ['electricity-power-outage', 'فصل قاطع الحي بشكل متكرر', 'يفصل قاطع الحي كل مساء ويحتاج الأمر إلى فحص الحمل والتوصيلات.', 'شارع بغداد - قرب الجسر'],
            ['electricity-dangerous-electrical-wire', 'شرر من لوحة إنارة', 'لوحظ شرر خفيف من لوحة إنارة عامة بعد المطر ويخشى السكان من حدوث تماس.', 'باب توما - الساحة الصغيرة'],
            ['electricity-power-outage', 'تغذية غير مستقرة للمحال', 'تتذبذب التغذية الكهربائية في صف المحال وتؤثر على الثلاجات وأجهزة الدفع.', 'ساحة الأمويين - الجهة الجنوبية'],
            ['electricity-dangerous-electrical-wire', 'سلك مقطوع قرب موقف الحافلات', 'يوجد سلك مقطوع قرب موقف الحافلات ويحتاج إلى عزل فوري قبل اقتراب المواطنين.', 'جرمانا - موقف المركز'],
        ];
        $water = [
            ['water-water-leakage', 'تسرب مياه من الخط الرئيسي', 'تسرب مستمر من الخط الرئيسي يملأ جانب الطريق ويؤثر على مرور السيارات والمشاة.', 'المزة - شارع الخدمات'],
            ['water-water-interruption', 'انقطاع المياه عن عدة أبنية', 'انقطعت المياه عن عدة أبنية منذ الأمس ولا توجد معلومات عن موعد عودة الضخ.', 'ركن الدين - شارع الخزان'],
            ['water-water-leakage', 'تجمع مياه حول غرفة العدادات', 'هناك تجمع مياه دائم حول غرفة العدادات ويزداد بعد تشغيل المضخات في الحي.', 'كفرسوسة - جانب المركز'],
            ['water-water-interruption', 'ضعف وصول المياه للطوابق العليا', 'لا تصل المياه إلى الطوابق العليا منذ ثلاثة أيام رغم انتظامها في الطابق الأرضي.', 'البرامكة - البناء رقم 12'],
            ['water-water-leakage', 'كسر في أنبوب قرب الرصيف', 'كسر ظاهر في أنبوب ماء قرب الرصيف يتسبب بهدر كبير ويحتاج إلى إصلاح سريع.', 'الميدان - شارع النهر'],
            ['water-water-interruption', 'توقف الضخ في الحي الشرقي', 'توقف الضخ في الحي الشرقي خلال ساعات النهار وأثر على المحال والمنازل.', 'مشروع دمر - الحي الشرقي'],
            ['water-water-leakage', 'تسرب قرب فتحة تصريف', 'تسرب المياه قرب فتحة التصريف أدى إلى تآكل الإسفلت وظهور برك مستمرة.', 'شارع بغداد - التقاطع الثاني'],
            ['water-water-interruption', 'انقطاع مفاجئ بعد أعمال صيانة', 'لم تعد المياه بعد أعمال صيانة قريبة ويحتاج السكان إلى توضيح للمدة المتوقعة.', 'باب توما - الحارة الشرقية'],
            ['water-water-leakage', 'ضغط مياه يسبب فيضاناً صغيراً', 'ارتفاع ضغط المياه في خط فرعي تسبب بفيضان صغير أمام مداخل الأبنية.', 'ساحة الأمويين - الجهة الغربية'],
            ['water-water-interruption', 'انخفاض الضغط في المنطقة الصناعية', 'ضغط المياه منخفض جداً في المنطقة الصناعية ويؤثر على الخدمات اليومية.', 'جرمانا - المنطقة الصناعية'],
        ];
        $transportation = [
            ['transportation-traffic-signal-issue', 'إشارة مرورية لا تعمل', 'الإشارة عند التقاطع الرئيسي متوقفة تماماً مما يسبب ازدحاماً وخطراً على المشاة.', 'المزة - تقاطع الجلاء'],
            ['transportation-public-transport-complaint', 'تأخر الحافلات في خط الجامعة', 'الحافلات في خط الجامعة تتأخر لفترات طويلة صباحاً ويضطر الطلاب للانتظار خارج المحطات.', 'البرامكة - موقف الجامعة'],
            ['transportation-traffic-signal-issue', 'توقيت الإشارة قصير للمشاة', 'الوقت المخصص لعبور المشاة قصير جداً ولا يكفي لكبار السن والأطفال.', 'كفرسوسة - تقاطع التنظيم'],
            ['transportation-public-transport-complaint', 'ازدحام شديد في حافلات الخط', 'الحافلات تصل مزدحمة باستمرار في ساعات الذروة ولا تتوقف لبعض الركاب.', 'الميدان - موقف السوق'],
            ['transportation-traffic-signal-issue', 'إشارة تحذير صفراء مستمرة', 'الإشارة تعمل باللون الأصفر المتقطع منذ أيام ولا تنظم حركة المرور عند التقاطع.', 'مشروع دمر - دوار الحديقة'],
            ['transportation-public-transport-complaint', 'عدم انتظام رحلات المساء', 'رحلات المساء في الخط الداخلي غير منتظمة ويطول الانتظار بعد الساعة الثامنة.', 'ركن الدين - موقف الساحة'],
            ['transportation-traffic-signal-issue', 'لوحة عبور المشاة غير واضحة', 'لوحة عبور المشاة عند المدرسة تالفة ولا يمكن رؤيتها بوضوح من قبل السائقين.', 'شارع بغداد - قرب المدرسة'],
            ['transportation-public-transport-complaint', 'توقف الحافلة بعيداً عن المحطة', 'الحافلة تتوقف بعيداً عن المحطة الرسمية مما يعرقل الركاب ويؤثر على حركة السير.', 'باب توما - المحطة الشمالية'],
            ['transportation-traffic-signal-issue', 'عطل في زر طلب العبور', 'زر طلب عبور المشاة لا يستجيب ويضطر الناس للعبور بين السيارات.', 'ساحة الأمويين - تقاطع المشاة'],
            ['transportation-public-transport-complaint', 'نقص حافلات خط جرمانا', 'عدد الحافلات في خط جرمانا غير كافٍ خلال الصباح ويزداد الازدحام يومياً.', 'جرمانا - موقف المركز'],
        ];
        $health = [
            ['health-clinic-service-complaint', 'تأخر الحصول على موعد في المركز الصحي', 'المواعيد المتاحة في المركز الصحي تتأخر لأسابيع ولا توجد وسيلة واضحة للاستفسار.', 'المزة - المركز الصحي'],
            ['health-public-health-issue', 'تجمع حشرات قرب حاوية طبية', 'توجد حشرات وروائح قرب حاوية طبية خارج المركز وتحتاج المنطقة إلى تعقيم وفحص.', 'ركن الدين - قرب المستوصف'],
            ['health-clinic-service-complaint', 'ازدحام في عيادة الأطفال', 'الانتظار في عيادة الأطفال طويل جداً ولا يوجد تنظيم واضح لدخول المراجعين.', 'كفرسوسة - عيادة الأطفال'],
            ['health-public-health-issue', 'مياه راكدة قرب مبنى عام', 'مياه راكدة قرب مبنى عام منذ أيام وقد تسبب بيئة غير صحية للسكان.', 'البرامكة - جانب المركز'],
            ['health-clinic-service-complaint', 'نقص إرشادات في قسم المواعيد', 'لا توجد إرشادات كافية في قسم المواعيد ويضطر كبار السن للتنقل بين النوافذ.', 'الميدان - المركز الطبي'],
            ['health-public-health-issue', 'حاجة لتعقيم ممر عام', 'الممر العام قرب المركز يحتاج إلى تعقيم بعد تزايد النفايات الطبية الصغيرة.', 'مشروع دمر - ممر الخدمات'],
            ['health-clinic-service-complaint', 'تأخر نتائج الفحوص المخبرية', 'تأخرت نتائج الفحوص المخبرية أكثر من الموعد المعلن ولا يوجد رد من القسم المختص.', 'شارع بغداد - المخبر العام'],
            ['health-public-health-issue', 'روائح غير صحية قرب منشأة عامة', 'تنبعث روائح غير صحية قرب منشأة عامة في فترة الظهيرة وتحتاج إلى معاينة ميدانية.', 'باب توما - الساحة الداخلية'],
            ['health-clinic-service-complaint', 'عدم توفر مقاعد انتظار كافية', 'قاعة الانتظار في المركز مزدحمة ولا تحتوي على مقاعد كافية للمراجعين.', 'ساحة الأمويين - المركز التخصصي'],
            ['health-public-health-issue', 'مخلفات طبية قرب نقطة تجميع', 'لوحظت مخلفات طبية بسيطة قرب نقطة تجميع ويجب التعامل معها وفق إجراءات السلامة.', 'جرمانا - نقطة الخدمات'],
        ];

        $content = array_merge($municipality, $electricity, $water, $transportation, $health);
        $statuses = [
            'submitted', 'under_review', 'assigned', 'in_progress', 'waiting_citizen', 'resolved', 'closed', 'rejected', 'escalated', 'resolved',
            'submitted', 'under_review', 'assigned', 'in_progress', 'waiting_citizen', 'resolved', 'closed', 'rejected', 'escalated', 'in_progress',
            'submitted', 'under_review', 'assigned', 'in_progress', 'waiting_citizen', 'resolved', 'closed', 'rejected', 'escalated', 'in_progress',
            'submitted', 'under_review', 'assigned', 'in_progress', 'waiting_citizen', 'resolved', 'closed', 'assigned', 'escalated', 'in_progress',
            'submitted', 'under_review', 'assigned', 'in_progress', 'in_progress', 'resolved', 'closed', 'closed', 'resolved', 'resolved',
        ];
        $priorities = [
            'medium', 'low', 'high', 'urgent', 'medium', 'high', 'low', 'low', 'urgent', 'low',
            'medium', 'low', 'medium', 'high', 'medium', 'high', 'low', 'high', 'urgent', 'medium',
            'medium', 'high', 'medium', 'high', 'low', 'medium', 'urgent', 'high', 'urgent', 'high',
            'medium', 'low', 'medium', 'high', 'medium', 'high', 'low', 'medium', 'urgent', 'high',
            'medium', 'low', 'medium', 'high', 'medium', 'urgent', 'medium', 'high', 'urgent', 'medium',
        ];
        $citizens = [
            'citizen@gcms.test', 'citizen@gcms.test', 'citizen@gcms.test', 'citizen@gcms.test', 'citizen@gcms.test',
            'citizen.one@gcms.test', 'citizen.one@gcms.test', 'citizen.one@gcms.test', 'citizen.one@gcms.test', 'citizen.one@gcms.test',
            'citizen@gcms.test', 'citizen@gcms.test', 'citizen.one@gcms.test', 'citizen.one@gcms.test', 'citizen.one@gcms.test',
            'citizen.two@gcms.test', 'citizen.two@gcms.test', 'citizen.two@gcms.test', 'citizen.two@gcms.test', 'citizen.two@gcms.test',
            'citizen@gcms.test', 'citizen@gcms.test', 'citizen.two@gcms.test', 'citizen.two@gcms.test', 'citizen.two@gcms.test',
            'citizen.three@gcms.test', 'citizen.three@gcms.test', 'citizen.three@gcms.test', 'citizen.three@gcms.test', 'citizen.three@gcms.test',
            'citizen@gcms.test', 'citizen.three@gcms.test', 'citizen.three@gcms.test', 'citizen.three@gcms.test', 'citizen.three@gcms.test',
            'citizen.three@gcms.test', 'citizen.four@gcms.test', 'citizen.four@gcms.test', 'citizen.four@gcms.test', 'citizen.four@gcms.test',
            'citizen@gcms.test', 'citizen@gcms.test', 'citizen.four@gcms.test', 'citizen.five@gcms.test', 'citizen.four@gcms.test',
            'citizen.four@gcms.test', 'citizen.four@gcms.test', 'citizen.five@gcms.test', 'citizen.five@gcms.test', 'citizen.five@gcms.test',
        ];
        $daysAgo = [0, 3, 5, 4, 2, 65, 65, 6, 3, 60, 1, 4, 2, 5, 1, 60, 55, 1, 0, 1, 2, 1, 2, 4, 2, 55, 55, 1, 3, 0, 2, 5, 2, 1, 2, 52, 45, 2, 0, 0, 1, 2, 1, 4, 1, 30, 45, 40, 20, 35];
        $locations = [
            [33.5138, 36.2765], [33.5216, 36.2690], [33.5007, 36.2854], [33.5092, 36.2898], [33.5271, 36.2750],
            [33.5345, 36.2840], [33.5168, 36.3031], [33.5174, 36.3170], [33.5085, 36.2818], [33.5340, 36.3263],
        ];
        $slots = array_fill(0, 50, 0);
        $departmentAssignmentCounts = array_fill_keys(['municipality', 'electricity', 'water', 'transportation', 'health'], 0);

        return collect($content)->map(function (array $item, int $index) use ($statuses, $priorities, $citizens, $daysAgo, $locations, &$slots, &$departmentAssignmentCounts): array {
            $number = $index + 1;
            $department = match (true) {
                $number <= 10 => 'municipality',
                $number <= 20 => 'electricity',
                $number <= 30 => 'water',
                $number <= 40 => 'transportation',
                default => 'health',
            };
            $status = $statuses[$index];
            $isAssigned = ! in_array($status, ['submitted', 'under_review', 'rejected'], true);
            $slot = 0;

            if ($isAssigned) {
                $slot = $departmentAssignmentCounts[$department] % 2;
                $departmentAssignmentCounts[$department]++;
            }

            $source = match (true) {
                $number <= 18 => 'web',
                $number <= 36 => 'mobile',
                $number <= 46 => 'offline_sync',
                default => 'admin',
            };
            $location = $locations[$index % count($locations)];

            return [
                'number' => $number,
                'department' => $department,
                'category' => $item[0],
                'title' => $item[1],
                'description' => $item[2],
                'address' => $item[3],
                'status' => $status,
                'priority' => $priorities[$index],
                'citizen' => $citizens[$index],
                'source' => $source,
                'days_ago' => $daysAgo[$index],
                'latitude' => $location[0],
                'longitude' => $location[1],
                'employee_slot' => $slot,
            ];
        })->all();
    }
}

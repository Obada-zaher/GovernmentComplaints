<?php

namespace Database\Seeders;

use App\Models\Complaint;
use App\Models\ComplaintAssignment;
use App\Models\ComplaintAttachment;
use App\Models\ComplaintCategory;
use App\Models\ComplaintStatusHistory;
use App\Models\Priority;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DepartmentsSeeder::class,
            ComplaintCategoriesSeeder::class,
            PrioritiesSeeder::class,
            SlaRulesSeeder::class,
            ClassificationRuleSeeder::class,
            DemoUsersSeeder::class,
        ]);

        $admin = User::query()->where('email', DemoUsersSeeder::ADMIN_EMAIL)->firstOrFail();
        $employees = User::query()
            ->whereIn('email', [
                DemoUsersSeeder::PRIMARY_EMPLOYEE_EMAIL,
                'electricity.employee@gcms.test',
                'water.employee@gcms.test',
                'transportation.employee@gcms.test',
                'health.employee@gcms.test',
            ])
            ->get()
            ->keyBy('email');
        $citizenEmails = [
            DemoUsersSeeder::PRIMARY_CITIZEN_EMAIL,
            'citizen.one@gcms.test',
            'citizen.two@gcms.test',
            'citizen.three@gcms.test',
            'citizen.four@gcms.test',
            'citizen.five@gcms.test',
        ];
        $seededCitizens = User::query()
            ->where('role', 'citizen')
            ->whereIn('email', $citizenEmails)
            ->get()
            ->keyBy('email');
        $citizens = collect($citizenEmails)
            ->map(fn (string $email): User => $seededCitizens->get($email) ?? throw new \RuntimeException("Missing demo citizen {$email}."))
            ->values();

        $priorities = Priority::query()->pluck('id', 'code');
        $categories = ComplaintCategory::query()->with('department')->get()->keyBy('code');

        $demoComplaints = [
            ['submitted', 'municipality-road-damage', 'medium', 'Pothole near school entrance', null, false],
            ['under_review', 'municipality-waste-collection', 'low', 'Garbage containers need collection', $employees[DemoUsersSeeder::PRIMARY_EMPLOYEE_EMAIL], false],
            ['assigned', 'electricity-power-outage', 'high', 'Power outage in neighborhood', $employees['electricity.employee@gcms.test'], false],
            ['in_progress', 'water-water-leakage', 'urgent', 'Large water leakage on main road', $employees['water.employee@gcms.test'], true],
            ['waiting_citizen', 'transportation-traffic-signal-issue', 'medium', 'Traffic signal timing issue', $employees['transportation.employee@gcms.test'], false],
            ['resolved', 'health-clinic-service-complaint', 'medium', 'Clinic appointment delay resolved', $employees['health.employee@gcms.test'], false],
            ['closed', 'municipality-street-lighting', 'low', 'Street light repaired and closed', $employees[DemoUsersSeeder::PRIMARY_EMPLOYEE_EMAIL], false],
            ['rejected', 'health-public-health-issue', 'low', 'Duplicate public health complaint', null, false],
            ['escalated', 'electricity-dangerous-electrical-wire', 'urgent', 'Exposed electrical wire escalated', $employees['electricity.employee@gcms.test'], true],
        ];

        foreach ($demoComplaints as $index => [$status, $categoryCode, $priorityCode, $title, $employee, $breached]) {
            $category = $categories[$categoryCode];
            $citizen = $citizens[$index % $citizens->count()];
            $createdAt = now()->subDays(12 - $index);

            $complaint = Complaint::query()->updateOrCreate(
                ['complaint_number' => 'GCMS-DEMO-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)],
                [
                    'citizen_id' => $citizen->id,
                    'department_id' => $category->department_id,
                    'category_id' => $category->id,
                    'priority_id' => $priorities[$priorityCode],
                    'assigned_employee_id' => $employee?->id,
                    'title' => $title,
                    'description' => 'Demo complaint for academic presentation and dashboard testing.',
                    'status' => $status,
                    'latitude' => 33.5138,
                    'longitude' => 36.2765,
                    'address' => 'Damascus demo area',
                    'source' => $index % 2 === 0 ? 'web' : 'mobile',
                    'classification_confidence' => 0.8500,
                    'due_at' => $breached ? now()->subHours(6) : now()->addHours(24 + $index),
                    'first_response_at' => in_array($status, ['under_review', 'assigned', 'in_progress', 'waiting_citizen', 'resolved', 'closed', 'rejected', 'escalated'], true) ? $createdAt->copy()->addHours(3) : null,
                    'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? $createdAt->copy()->addDays(2) : null,
                    'closed_at' => $status === 'closed' ? $createdAt->copy()->addDays(3) : null,
                    'is_sla_breached' => $breached,
                    'created_at' => $createdAt,
                    'updated_at' => now()->subDays(max(0, 8 - $index)),
                ],
            );

            ComplaintStatusHistory::query()->firstOrCreate(
                ['complaint_id' => $complaint->id, 'to_status' => 'submitted'],
                ['changed_by' => $citizen->id, 'from_status' => null, 'note' => 'Demo complaint submitted by citizen.', 'duration_minutes' => null],
            );

            if ($status !== 'submitted') {
                ComplaintStatusHistory::query()->firstOrCreate(
                    ['complaint_id' => $complaint->id, 'to_status' => $status],
                    ['changed_by' => $employee?->id ?? $admin->id, 'from_status' => 'submitted', 'note' => 'Demo workflow status for presentation.', 'duration_minutes' => 180],
                );
            }

            if ($employee) {
                ComplaintAssignment::query()->firstOrCreate(
                    ['complaint_id' => $complaint->id, 'assigned_to' => $employee->id],
                    ['assigned_by' => $admin->id, 'department_id' => $category->department_id, 'note' => 'Demo assignment.', 'assigned_at' => $createdAt->copy()->addHours(4)],
                );
            }

            if ($index < 3) {
                ComplaintAttachment::query()->firstOrCreate(
                    ['complaint_id' => $complaint->id, 'file_name' => 'demo-attachment-'.($index + 1).'.jpg'],
                    ['uploaded_by' => $citizen->id, 'original_name' => 'demo-photo-'.($index + 1).'.jpg', 'file_path' => 'complaints/demo-attachment-'.($index + 1).'.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 120000 + ($index * 1000), 'disk' => 'public'],
                );
            }

            UserNotification::query()->firstOrCreate(
                ['user_id' => $citizen->id, 'complaint_id' => $complaint->id, 'type' => 'complaint_status_updated'],
                ['title' => 'Demo complaint update', 'body' => "Complaint {$complaint->complaint_number} is {$status}.", 'data' => ['complaint_id' => $complaint->id, 'status' => $status]],
            );
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Complaint;
use App\Models\ComplaintAssignment;
use App\Models\ComplaintAttachment;
use App\Models\ComplaintCategory;
use App\Models\ComplaintStatusHistory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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
        ]);

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@gcms.test'],
            [
                'name' => 'Demo Admin',
                'phone' => '0990000001',
                'national_id' => '10000000001',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'department_id' => null,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $departments = Department::query()->pluck('id', 'code');
        $employees = collect([
            ['name' => 'Municipality Employee', 'email' => 'employee@gcms.test', 'phone' => '0990000002', 'department' => 'municipality'],
            ['name' => 'Electricity Employee', 'email' => 'electricity.employee@gcms.test', 'phone' => '0990000004', 'department' => 'electricity'],
            ['name' => 'Water Employee', 'email' => 'water.employee@gcms.test', 'phone' => '0990000005', 'department' => 'water'],
            ['name' => 'Health Employee', 'email' => 'health.employee@gcms.test', 'phone' => '0990000006', 'department' => 'health'],
        ])->map(fn (array $employee): User => User::query()->updateOrCreate(
            ['email' => $employee['email']],
            [
                'name' => $employee['name'],
                'phone' => $employee['phone'],
                'national_id' => fake()->unique()->numerify('2##########'),
                'password' => Hash::make('password'),
                'role' => 'employee',
                'department_id' => $departments[$employee['department']],
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        ));

        $citizens = collect([
            ['name' => 'Citizen User', 'email' => 'citizen@gcms.test', 'phone' => '0990000003'],
            ['name' => 'Demo Citizen One', 'email' => 'citizen.one@gcms.test', 'phone' => '0990000007'],
            ['name' => 'Demo Citizen Two', 'email' => 'citizen.two@gcms.test', 'phone' => '0990000008'],
        ])->map(fn (array $citizen): User => User::query()->updateOrCreate(
            ['email' => $citizen['email']],
            [
                'name' => $citizen['name'],
                'phone' => $citizen['phone'],
                'national_id' => fake()->unique()->numerify('3##########'),
                'password' => Hash::make('password'),
                'role' => 'citizen',
                'department_id' => null,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        ));

        $priorities = Priority::query()->pluck('id', 'code');
        $categories = ComplaintCategory::query()->with('department')->get()->keyBy('code');

        $demoComplaints = [
            ['submitted', 'municipality-road-damage', 'medium', 'Pothole near school entrance', null, false],
            ['under_review', 'municipality-waste-collection', 'low', 'Garbage containers need collection', $employees[0], false],
            ['assigned', 'electricity-power-outage', 'high', 'Power outage in neighborhood', $employees[1], false],
            ['in_progress', 'water-water-leakage', 'urgent', 'Large water leakage on main road', $employees[2], true],
            ['waiting_citizen', 'transportation-traffic-signal-issue', 'medium', 'Traffic signal timing issue', $employees[0], false],
            ['resolved', 'health-clinic-service-complaint', 'medium', 'Clinic appointment delay resolved', $employees[3], false],
            ['closed', 'municipality-street-lighting', 'low', 'Street light repaired and closed', $employees[0], false],
            ['rejected', 'health-public-health-issue', 'low', 'Duplicate public health complaint', null, false],
            ['escalated', 'electricity-dangerous-electrical-wire', 'urgent', 'Exposed electrical wire escalated', $employees[1], true],
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

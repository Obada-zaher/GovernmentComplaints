<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintAssignment;
use App\Models\ComplaintAttachment;
use App\Models\ComplaintCategory;
use App\Models\ComplaintStatusHistory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_run_successfully(): void
    {
        $this->assertTrue(Schema::hasTable('departments'));
        $this->assertTrue(Schema::hasTable('complaint_categories'));
        $this->assertTrue(Schema::hasTable('priorities'));
        $this->assertTrue(Schema::hasTable('sla_rules'));
        $this->assertTrue(Schema::hasTable('complaints'));
        $this->assertTrue(Schema::hasTable('complaint_attachments'));
        $this->assertTrue(Schema::hasTable('complaint_status_histories'));
        $this->assertTrue(Schema::hasTable('complaint_assignments'));
        $this->assertTrue(Schema::hasTable('otp_codes'));
        $this->assertTrue(Schema::hasTable('user_notifications'));
        $this->assertTrue(Schema::hasTable('offline_submissions'));
        $this->assertTrue(Schema::hasTable('complaint_classification_rules'));
        $this->assertTrue(Schema::hasTable('report_snapshots'));

        $this->assertTrue(Schema::hasColumns('users', [
            'phone',
            'national_id',
            'role',
            'department_id',
            'is_active',
            'phone_verified_at',
            'last_login_at',
        ]));
    }

    public function test_departments_and_categories_are_seeded(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('departments', [
            'name' => 'Municipality',
            'code' => 'municipality',
        ]);

        $this->assertDatabaseHas('complaint_categories', [
            'name' => 'Road Damage',
            'code' => 'municipality-road-damage',
        ]);

        $this->assertSame(5, Department::query()->count());
        $this->assertSame(11, ComplaintCategory::query()->count());
    }

    public function test_priorities_are_seeded(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['low', 'medium', 'high', 'urgent'] as $code) {
            $this->assertDatabaseHas('priorities', ['code' => $code]);
        }

        $this->assertSame(4, Priority::query()->count());
    }

    public function test_users_are_seeded(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@gcms.test',
            'role' => 'admin',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'employee@gcms.test',
            'role' => 'employee',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'citizen@gcms.test',
            'role' => 'citizen',
        ]);
    }

    public function test_complaint_can_be_created_using_factory(): void
    {
        $complaint = Complaint::factory()->create();

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'complaint_number' => $complaint->complaint_number,
            'status' => 'submitted',
        ]);
    }

    public function test_complaint_relationships_work(): void
    {
        $department = Department::factory()->create();
        $category = ComplaintCategory::factory()->create(['department_id' => $department->id]);
        $priority = Priority::factory()->create();
        $citizen = User::factory()->citizen()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);

        $complaint = Complaint::factory()->create([
            'citizen_id' => $citizen->id,
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
            'assigned_employee_id' => $employee->id,
        ]);

        $attachment = ComplaintAttachment::factory()->create([
            'complaint_id' => $complaint->id,
            'uploaded_by' => $citizen->id,
        ]);

        $notification = UserNotification::factory()->create([
            'user_id' => $citizen->id,
            'complaint_id' => $complaint->id,
        ]);

        $this->assertTrue($complaint->citizen->is($citizen));
        $this->assertTrue($complaint->department->is($department));
        $this->assertTrue($complaint->category->is($category));
        $this->assertTrue($complaint->priority->is($priority));
        $this->assertTrue($complaint->assignedEmployee->is($employee));
        $this->assertTrue($complaint->attachments->first()->is($attachment));
        $this->assertTrue($complaint->notifications->first()->is($notification));
    }

    public function test_complaint_status_history_relationship_works(): void
    {
        $complaint = Complaint::factory()->create();
        $user = User::factory()->employee()->create();

        $history = ComplaintStatusHistory::factory()->create([
            'complaint_id' => $complaint->id,
            'changed_by' => $user->id,
            'from_status' => 'submitted',
            'to_status' => 'under_review',
        ]);

        $this->assertTrue($complaint->statusHistories->first()->is($history));
        $this->assertTrue($history->complaint->is($complaint));
        $this->assertTrue($history->changedBy->is($user));
    }

    public function test_complaint_assignment_relationship_works(): void
    {
        $department = Department::factory()->create();
        $complaint = Complaint::factory()->create(['department_id' => $department->id]);
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create(['department_id' => $department->id]);

        $assignment = ComplaintAssignment::factory()->create([
            'complaint_id' => $complaint->id,
            'assigned_by' => $admin->id,
            'assigned_to' => $employee->id,
            'department_id' => $department->id,
        ]);

        $this->assertTrue($complaint->assignments->first()->is($assignment));
        $this->assertTrue($assignment->complaint->is($complaint));
        $this->assertTrue($assignment->assignedBy->is($admin));
        $this->assertTrue($assignment->assignedTo->is($employee));
        $this->assertTrue($assignment->department->is($department));
    }
}

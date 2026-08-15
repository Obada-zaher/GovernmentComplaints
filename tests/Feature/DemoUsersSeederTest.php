<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_keeps_demo_data_opt_in(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, User::query()->count());
        $this->assertSame(0, Complaint::query()->count());
    }

    public function test_demo_data_seeder_creates_deterministic_reference_data_and_users(): void
    {
        $this->seed(DemoDataSeeder::class);

        foreach ([
            'municipality' => 'Municipality',
            'electricity' => 'Electricity',
            'water' => 'Water',
            'transportation' => 'Transportation',
            'health' => 'Health',
        ] as $code => $name) {
            $this->assertDatabaseHas('departments', ['code' => $code, 'name' => $name]);
        }

        $this->assertSame(10, User::query()->where('role', 'employee')->count());
        $this->assertSame(6, User::query()->where('role', 'citizen')->count());

        foreach (Department::query()->orderBy('code')->get() as $department) {
            $this->assertSame(2, User::query()
                ->where('role', 'employee')
                ->where('department_id', $department->id)
                ->count());
        }

        $admin = User::query()->where('email', DemoUsersSeeder::ADMIN_EMAIL)->firstOrFail();
        $employee = User::query()->where('email', DemoUsersSeeder::PRIMARY_EMPLOYEE_EMAIL)->with('department')->firstOrFail();
        $citizen = User::query()->where('email', DemoUsersSeeder::PRIMARY_CITIZEN_EMAIL)->firstOrFail();

        $this->assertSame('admin', $admin->role);
        $this->assertNull($admin->department_id);
        $this->assertSame('employee', $employee->role);
        $this->assertSame('municipality', $employee->department?->code);
        $this->assertSame('citizen', $citizen->role);
        $this->assertNull($citizen->department_id);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($employee->is_active);
        $this->assertTrue($citizen->is_active);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertNotNull($employee->email_verified_at);
        $this->assertNotNull($citizen->email_verified_at);
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertTrue(Hash::check('password', $employee->password));
        $this->assertTrue(Hash::check('password', $citizen->password));
    }

    public function test_demo_data_seeder_is_idempotent_and_does_not_change_unrelated_users(): void
    {
        $unrelatedUser = User::factory()->citizen()->create([
            'name' => 'Manual User',
            'email' => 'manual.user@example.test',
            'phone' => '0999999999',
            'national_id' => '99999999999',
        ]);

        $this->seed(DemoDataSeeder::class);
        $firstUserCount = User::query()->count();
        $firstComplaintCount = Complaint::query()->count();

        $this->seed(DemoDataSeeder::class);

        $this->assertSame($firstUserCount, User::query()->count());
        $this->assertSame($firstComplaintCount, Complaint::query()->count());
        $this->assertSame(9, Complaint::query()->count());
        $this->assertSame('Manual User', $unrelatedUser->fresh()->name);
        $this->assertSame('0999999999', $unrelatedUser->fresh()->phone);
        $this->assertSame('99999999999', $unrelatedUser->fresh()->national_id);

        $this->assertSame(10, User::query()->where('role', 'employee')->count());
        $this->assertSame(6, User::query()->where('role', 'citizen')->count() - 1);
        $this->assertSame(1, User::query()->where('role', 'admin')->count());
        $this->assertSame(0, User::query()->where('role', 'employee')->whereNull('department_id')->count());
        $this->assertSame(0, User::query()->where('role', 'citizen')->whereNotNull('department_id')->count());
    }
}

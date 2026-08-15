<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoUsersSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'admin@gcms.test';

    public const PRIMARY_EMPLOYEE_EMAIL = 'employee@gcms.test';

    public const PRIMARY_CITIZEN_EMAIL = 'citizen@gcms.test';

    /**
     * Seed the complete deterministic demo account cohort.
     */
    public function run(): void
    {
        $this->seedUsers($this->users());
    }

    /**
     * Seed the three long-standing accounts used by local development and Postman.
     */
    public function seedCoreAccounts(): void
    {
        $coreEmails = [self::ADMIN_EMAIL, self::PRIMARY_EMPLOYEE_EMAIL, self::PRIMARY_CITIZEN_EMAIL];

        $this->seedUsers(array_values(array_filter(
            $this->users(),
            fn (array $user): bool => in_array($user['email'], $coreEmails, true),
        )));
    }

    /**
     * @param  array<int, array<string, string|null>>  $users
     */
    private function seedUsers(array $users): void
    {
        $departmentIds = Department::query()->pluck('id', 'code');
        $verifiedAt = CarbonImmutable::create(2026, 1, 1, 0, 0, 0, 'UTC');

        foreach ($users as $user) {
            $departmentCode = $user['department_code'];
            $departmentId = $departmentCode === null
                ? null
                : $departmentIds->get($departmentCode);

            if ($departmentCode !== null && $departmentId === null) {
                throw new RuntimeException("The {$departmentCode} department must be seeded before demo users.");
            }

            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'phone' => $user['phone'],
                    'national_id' => $user['national_id'],
                    'password' => Hash::make('password'),
                    'role' => $user['role'],
                    'department_id' => $departmentId,
                    'is_active' => true,
                    'email_verified_at' => $verifiedAt,
                ],
            );
        }
    }

    /**
     * @return array<int, array{name: string, email: string, phone: string, national_id: string, role: string, department_code: string|null}>
     */
    private function users(): array
    {
        return [
            [
                'name' => 'Demo Admin',
                'email' => self::ADMIN_EMAIL,
                'phone' => '0990000001',
                'national_id' => '10000000001',
                'role' => 'admin',
                'department_code' => null,
            ],
            [
                'name' => 'Municipality Employee',
                'email' => self::PRIMARY_EMPLOYEE_EMAIL,
                'phone' => '0990000002',
                'national_id' => '20000000001',
                'role' => 'employee',
                'department_code' => 'municipality',
            ],
            [
                'name' => 'Rana Al-Khatib',
                'email' => 'municipality.operations@gcms.test',
                'phone' => '0990000010',
                'national_id' => '20000000002',
                'role' => 'employee',
                'department_code' => 'municipality',
            ],
            [
                'name' => 'Electricity Employee',
                'email' => 'electricity.employee@gcms.test',
                'phone' => '0990000004',
                'national_id' => '20000000003',
                'role' => 'employee',
                'department_code' => 'electricity',
            ],
            [
                'name' => 'Omar Al-Haddad',
                'email' => 'electricity.maintenance@gcms.test',
                'phone' => '0990000011',
                'national_id' => '20000000004',
                'role' => 'employee',
                'department_code' => 'electricity',
            ],
            [
                'name' => 'Water Employee',
                'email' => 'water.employee@gcms.test',
                'phone' => '0990000005',
                'national_id' => '20000000005',
                'role' => 'employee',
                'department_code' => 'water',
            ],
            [
                'name' => 'Lina Al-Masri',
                'email' => 'water.network@gcms.test',
                'phone' => '0990000012',
                'national_id' => '20000000006',
                'role' => 'employee',
                'department_code' => 'water',
            ],
            [
                'name' => 'Khaled Al-Salem',
                'email' => 'transportation.employee@gcms.test',
                'phone' => '0990000013',
                'national_id' => '20000000007',
                'role' => 'employee',
                'department_code' => 'transportation',
            ],
            [
                'name' => 'Maya Darwish',
                'email' => 'transportation.operations@gcms.test',
                'phone' => '0990000014',
                'national_id' => '20000000008',
                'role' => 'employee',
                'department_code' => 'transportation',
            ],
            [
                'name' => 'Health Employee',
                'email' => 'health.employee@gcms.test',
                'phone' => '0990000006',
                'national_id' => '20000000009',
                'role' => 'employee',
                'department_code' => 'health',
            ],
            [
                'name' => 'Yousef Al-Najjar',
                'email' => 'health.services@gcms.test',
                'phone' => '0990000015',
                'national_id' => '20000000010',
                'role' => 'employee',
                'department_code' => 'health',
            ],
            [
                'name' => 'Citizen User',
                'email' => self::PRIMARY_CITIZEN_EMAIL,
                'phone' => '0990000003',
                'national_id' => '30000000001',
                'role' => 'citizen',
                'department_code' => null,
            ],
            [
                'name' => 'Demo Citizen One',
                'email' => 'citizen.one@gcms.test',
                'phone' => '0990000007',
                'national_id' => '30000000002',
                'role' => 'citizen',
                'department_code' => null,
            ],
            [
                'name' => 'Demo Citizen Two',
                'email' => 'citizen.two@gcms.test',
                'phone' => '0990000008',
                'national_id' => '30000000003',
                'role' => 'citizen',
                'department_code' => null,
            ],
            [
                'name' => 'Nour Al-Hamwi',
                'email' => 'citizen.three@gcms.test',
                'phone' => '0990000016',
                'national_id' => '30000000004',
                'role' => 'citizen',
                'department_code' => null,
            ],
            [
                'name' => 'Samer Al-Ahmad',
                'email' => 'citizen.four@gcms.test',
                'phone' => '0990000017',
                'national_id' => '30000000005',
                'role' => 'citizen',
                'department_code' => null,
            ],
            [
                'name' => 'Hala Al-Zein',
                'email' => 'citizen.five@gcms.test',
                'phone' => '0990000018',
                'national_id' => '30000000006',
                'role' => 'citizen',
                'department_code' => null,
            ],
        ];
    }
}

<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        $municipality = Department::query()->where('code', 'municipality')->firstOrFail();

        User::query()->updateOrCreate(
            ['email' => 'admin@gcms.test'],
            [
                'name' => 'Admin User',
                'phone' => '0990000001',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'department_id' => null,
                'is_active' => true,
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'employee@gcms.test'],
            [
                'name' => 'Municipality Employee',
                'phone' => '0990000002',
                'password' => Hash::make('password'),
                'role' => 'employee',
                'department_id' => $municipality->id,
                'is_active' => true,
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'citizen@gcms.test'],
            [
                'name' => 'Citizen User',
                'phone' => '0990000003',
                'password' => Hash::make('password'),
                'role' => 'citizen',
                'department_id' => null,
                'is_active' => true,
            ],
        );
    }
}

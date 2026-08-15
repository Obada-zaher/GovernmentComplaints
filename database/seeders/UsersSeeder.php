<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        app(DemoUsersSeeder::class)->seedCoreAccounts();
    }
}

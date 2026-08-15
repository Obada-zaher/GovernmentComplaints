<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    /**
     * Seed the complete opt-in academic demonstration dataset.
     */
    public function run(): void
    {
        $this->call([
            DepartmentsSeeder::class,
            ComplaintCategoriesSeeder::class,
            PrioritiesSeeder::class,
            SlaRulesSeeder::class,
            ClassificationRuleSeeder::class,
            DemoUsersSeeder::class,
            DemoComplaintsSeeder::class,
            DemoOperationalDataSeeder::class,
        ]);
    }
}

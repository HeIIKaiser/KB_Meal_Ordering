<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production: only reference data (slots, price types, initial prices).
     * Demo data: php artisan db:seed --class=DemoDataSeeder
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);
    }
}

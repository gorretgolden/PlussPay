<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionsSeeder::class,
            RoleAndUserSeeder::class,
            ExchangeRateSeeder::class,
            SettingsSeeder::class,
        ]);
    }
}

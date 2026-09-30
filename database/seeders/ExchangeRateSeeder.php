<?php

namespace Database\Seeders;

use App\Models\ExchangeRate;
use Illuminate\Database\Seeder;

class ExchangeRateSeeder extends Seeder
{
    public function run(): void
    {
        ExchangeRate::updateOrCreate(
            ['from_currency' => 'USD', 'to_currency' => 'RMB'],
            ['rate' => 7.24, 'is_active' => true]
        );

        ExchangeRate::updateOrCreate(
            ['from_currency' => 'UGX', 'to_currency' => 'USD'],
            ['rate' => 0.00027, 'is_active' => true]
        );
    }
}

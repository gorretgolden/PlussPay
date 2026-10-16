<?php

namespace Database\Seeders;

use App\Models\ExchangeRate;
use Illuminate\Database\Seeder;

class ExchangeRateSeeder extends Seeder
{
    public function run(): void
    {
        // The desk reads two prices, so two are seeded, and only where a pair has nothing in use.
        // The reverse-direction row used to be seeded here too, which put a third price in use that
        // no conversion reads. An earlier price is history and stays retired: bringing one back is a
        // decision somebody makes on the rates page, not something a re-seed does by itself.
        foreach ([['USD', 'UGX', 3760], ['USD', 'RMB', 7.24]] as [$from, $to, $rate]) {
            if (ExchangeRate::getActiveRate($from, $to) === null) {
                ExchangeRate::setPair($from, $to, $rate);
            }
        }
    }
}

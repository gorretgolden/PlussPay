<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use Illuminate\Console\Command;

/**
 * The desk reads two prices, USD to UGX and USD to RMB, and one row per pair is enough for both
 * directions of travel because that row already carries a buying and a selling figure. Rows for
 * the reverse direction, and second live rows for a pair that is already priced, are what the free
 * pair picker used to create before it was removed on 2026-10-14. Nothing computes from them, but
 * they sit in the list of prices in use and read as if they were needed, which is how they survive.
 *
 * This takes them out of use. It never deletes a row: the figures, the date and the person who
 * entered them stay, so every transfer priced under one still reads back the same.
 */
class SetAsideStrayRates extends Command
{
    protected $signature = 'exchange-rates:set-aside-strays
        {--apply : Take them out of use. Without this option nothing is touched}';

    protected $description = 'Set aside any price still in use that the desk does not read, leaving the two desk pairs alone';

    public function handle(): int
    {
        $strays = ExchangeRate::liveRowsOutsideRule();

        if ($strays->isEmpty()) {
            $this->info('The prices in use are '.ExchangeRate::deskPairsList().', which is what the desk reads. Nothing to set aside.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        foreach ($strays as $rate) {
            $pair = $rate->from_currency.' → '.$rate->to_currency;
            $price = $this->trim((float) ($rate->sell_rate ?: $rate->rate));
            $reason = ExchangeRate::isDeskPair($rate->from_currency, $rate->to_currency)
                ? 'this pair already has a price in use'
                : 'the desk does not read money in that direction';

            $this->line('  '.$pair.' '.$price.'  '.($apply ? 'out of use' : 'would go out of use').': '.$reason.'.');

            if (! $apply) {
                continue;
            }

            $rate->setAside();

            ActivityLog::create([
                'action' => 'exchange_rate_deactivated',
                'description' => "Price {$pair} {$price} taken out of use by exchange-rates:set-aside-strays ({$reason})",
                'old_value' => 'in use',
                'new_value' => 'earlier',
            ]);
        }

        $count = $strays->count();
        $rows = $count === 1 ? '1 row' : $count.' rows';

        $this->line('');
        $this->line($apply
            ? $rows.' out of use. '.ExchangeRate::deskPairsList().' still price bookings, and nothing was deleted.'
            : 'Dry run: nothing was changed. Add --apply to take the '.$rows.' out of use.');

        return self::SUCCESS;
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}

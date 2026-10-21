<?php

namespace App\Console\Commands;

use App\Services\MarketRateService;
use Illuminate\Console\Command;
use RuntimeException;

class PullMarketRates extends Command
{
    protected $signature = 'rates:pull
                            {--backfill=0 : Record this many earlier daily readings the first time}';

    protected $description = 'Pull the USD to UGX and USD to RMB mid-market rate, record it, and move the automated buying rate';

    public function handle(MarketRateService $market): int
    {
        try {
            $result = $market->pull((int) $this->option('backfill'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Market rates pulled.');
        foreach ($result['quotes'] as $quote => $snapshot) {
            $this->line('  USD -> '.$quote.' '.$this->trim((float) $snapshot->rate)
                .' for '.$snapshot->rate_date->toDateString().' from '.$snapshot->source);
        }

        if ($result['backfilled'] > 0) {
            $this->line('  Backfilled '.$result['backfilled'].' earlier day(s).');
        }

        if ($result['applied']) {
            $this->info('  Buying rate set to '.$this->trim((float) $result['applied']->buy_rate).'.');
        } elseif ($result['note']) {
            $this->line('  '.$result['note']);
        }

        return self::SUCCESS;
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
    }
}

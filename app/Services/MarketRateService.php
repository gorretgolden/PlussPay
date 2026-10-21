<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\RateSnapshot;
use App\Models\Setting;

class MarketRateService
{
    private const PAIRS = ['UGX', 'RMB'];

    public function __construct(private readonly MarketRateFeed $feed)
    {
    }

    /**
     * Pull once, record both readings, and move the buying side if she has automated it.
     *
     * @return array{quotes: array<string, RateSnapshot>, applied: ?ExchangeRate, note: ?string, backfilled: int}
     */
    public function pull(int $backfillDays = 0): array
    {
        $backfilled = $backfillDays > 0 ? $this->backfill($backfillDays) : 0;
        $reading = $this->feed->latest();

        $quotes = [];
        foreach (self::PAIRS as $quote) {
            $quotes[$quote] = RateSnapshot::create([
                'base_currency' => 'USD',
                'quote_currency' => $quote,
                'rate' => $reading['rates'][$quote],
                'rate_date' => $reading['rate_date'],
                'source' => $reading['source'],
                'fetched_at' => now(),
            ]);
        }

        $applied = $this->applyLatestBuyingRate();
        $state = $this->buyingRateState($applied !== null);

        return [
            'quotes' => $quotes,
            'applied' => $applied,
            'note' => $state['state'] === 'off' ? null : $state['text'],
            'backfilled' => $backfilled,
        ];
    }

    /**
     * The automation only ever touches the buying side of the shilling pair. The yuan
     * buying price is a settlement figure that has to sit above the customer's quote,
     * so the market there is a reference rather than a value to copy.
     *
     * @return array{state: string, text: string}
     */
    public function buyingRateState(bool $appliedNow = false): array
    {
        if (Setting::get('market_buy_auto', 'off') !== 'on') {
            return [
                'state' => 'off',
                'text' => 'Off. The buying and selling prices are the ones typed above.',
            ];
        }

        $latest = RateSnapshot::latestFor('USD', 'UGX');

        if (! $latest) {
            return ['state' => 'waiting', 'text' => 'Waiting for the first pull to set the buying rate.'];
        }

        if ($latest->fetched_at->lt(now()->subHours(26))) {
            return ['state' => 'stale', 'text' => 'The last reading is more than a day old. Pull now to bring the buying rate up to date.'];
        }

        $pair = ExchangeRate::usdUgxPair();

        if (! $pair) {
            return ['state' => 'no_pair', 'text' => 'There is no live USD → UGX price for the buying rate to sit on.'];
        }

        $market = (float) $latest->rate;

        if ($market >= $pair['sell']) {
            return [
                'state' => 'above',
                'text' => 'Not applied. The market is '.$this->figure($market, 'UGX').' UGX per USD, at or above your selling rate of '.$this->figure($pair['sell'], 'UGX').'. Raise the selling rate first; automation never turns the pair into a loss.',
            ];
        }

        if (abs($pair['buy'] - $market) < 0.0000005) {
            return [
                'state' => 'applied',
                'text' => ($appliedNow ? 'Buying rate set to ' : 'Following the market at ')
                    .$this->figure($market, 'UGX').' UGX per USD, pulled '.$latest->fetched_at->format('d M, H:i').'.',
            ];
        }

        return [
            'state' => 'different',
            'text' => 'The buying rate is '.$this->figure($pair['buy'], 'UGX').', but the latest reading is '.$this->figure($market, 'UGX').'. Pull now to apply it.',
        ];
    }

    public function applyLatestBuyingRate(): ?ExchangeRate
    {
        if ($this->buyingRateState()['state'] !== 'different') {
            return null;
        }

        $latest = RateSnapshot::latestFor('USD', 'UGX');
        $live = ExchangeRate::getActiveRate('USD', 'UGX');

        if (! $latest || ! $live) {
            return null;
        }

        $before = $live->buy_rate === null ? null : (float) $live->buy_rate;
        $market = (float) $latest->rate;

        $live->update(['buy_rate' => $this->stored($market)]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'market_buying_rate_applied',
            'description' => 'USD → UGX buying rate moved from '.($before === null ? 'not set' : $this->figure($before, 'UGX'))
                .' to '.$this->figure($market, 'UGX').' on the market reading from '.$latest->source,
            'old_value' => $before === null ? null : (string) $before,
            'new_value' => $this->stored($market),
        ]);

        return $live->fresh();
    }

    private function backfill(int $days): int
    {
        $recorded = 0;
        $days = min(max($days, 1), 60);

        for ($offset = 1; $offset <= $days; $offset++) {
            $date = now()->subDays($offset);

            $already = RateSnapshot::query()
                ->where('base_currency', 'USD')
                ->where('quote_currency', 'UGX')
                ->whereDate('rate_date', $date->toDateString())
                ->exists();

            if ($already) {
                continue;
            }

            $reading = $this->feed->history($date);

            if ($reading === null) {
                continue;
            }

            foreach (self::PAIRS as $quote) {
                RateSnapshot::create([
                    'base_currency' => 'USD',
                    'quote_currency' => $quote,
                    'rate' => $reading['rates'][$quote],
                    'rate_date' => $reading['rate_date'],
                    'source' => $reading['source'],
                    'fetched_at' => now(),
                ]);
            }

            $recorded++;
        }

        return $recorded;
    }

    private function figure(float $value, string $quote): string
    {
        return $quote === 'UGX'
            ? number_format($value, 2)
            : rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private function stored(float $value): string
    {
        return number_format($value, 6, '.', '');
    }
}

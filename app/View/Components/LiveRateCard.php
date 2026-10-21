<?php

namespace App\View\Components;

use App\Models\ExchangeRate;
use App\Models\RateSnapshot;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class LiveRateCard extends Component
{
    public function render(): View
    {
        $user = auth()->user();
        // The live dollar reading is a market figure, not the counter price. It stays with the
        // Super Admin and the lead agent; everyone else sees only the rate the desk sells at.
        $showMarket = $user !== null
            && ($user->hasRole('superadmin') || $user->hasRole('super-agent'));

        $pairs = [];
        foreach (['UGX' => 'Dollar to shilling', 'RMB' => 'Dollar to yuan'] as $quote => $label) {
            $row = ExchangeRate::getActiveRate('USD', $quote);
            $sell = $row ? (float) ($row->sell_rate ?: $row->rate) : null;

            $pairs[$quote] = [
                'label' => $label,
                'unit' => $quote,
                'value' => $sell === null || $sell <= 0 ? null : $this->figure($sell, $quote),
                'updated_at' => $row?->updated_at,
            ];
        }

        $market = [];
        $lastPulled = null;
        $stale = false;

        if ($showMarket) {
            $lastPulled = RateSnapshot::lastPulledAt();
            $stale = $lastPulled !== null && $lastPulled->lt(now()->subHours(26));

            foreach (['UGX', 'RMB'] as $quote) {
                $latest = RateSnapshot::latestFor('USD', $quote);

                if (! $latest) {
                    continue;
                }

                $history = RateSnapshot::dailyCloses('USD', $quote, 30);
                $previous = $history->count() > 1 ? $history[$history->count() - 2] : null;
                $change = $previous ? (float) $latest->rate - (float) $previous->rate : null;
                $percent = $change !== null && (float) $previous->rate > 0
                    ? ($change / (float) $previous->rate) * 100
                    : null;
                $rising = $change !== null && $change > 0;
                $falling = $change !== null && $change < 0;

                $market[] = [
                    'label' => $quote === 'UGX' ? 'shilling' : 'yuan',
                    'unit' => $quote,
                    'display' => $this->figure((float) $latest->rate, $quote),
                    'change_text' => $this->changeText($change, $percent, $previous?->rate_date, $quote),
                    'source' => $latest->source,
                    'tone' => $rising ? 'text-green-700' : ($falling ? 'text-red-700' : 'text-gray-500'),
                    'line' => $rising ? 'text-green-700' : ($falling ? 'text-red-700' : 'text-gray-400'),
                    'points' => $history->count() > 1 ? $this->points($history) : null,
                    'chart_label' => $history->count() > 1
                        ? 'USD to '.$quote.' from '.$this->figure((float) $history->first()->rate, $quote)
                            .' on '.$history->first()->rate_date->format('d M').' to '
                            .$this->figure((float) $latest->rate, $quote).' on '.$latest->rate_date->format('d M')
                        : '',
                ];
            }
        }

        // The two readings can come from different feeds when the first one fails, so the footer
        // names every feed that supplied a figure rather than attributing both to one.
        $sources = collect($market)->pluck('source')->unique()->values()->all();

        return view('components.live-rate-card', compact('pairs', 'market', 'lastPulled', 'sources', 'stale', 'showMarket'));
    }

    private function figure(float $value, string $quote): string
    {
        $decimals = $quote === 'UGX' ? 2 : 4;

        return rtrim(rtrim(number_format($value, $decimals, '.', ','), '0'), '.');
    }

    private function changeText(?float $change, ?float $percent, ?Carbon $since, string $quote): string
    {
        if ($since === null) {
            return 'First reading recorded.';
        }

        if ($change === null || abs($change) < 0.0000005) {
            return 'Unchanged since '.$since->format('d M').'.';
        }

        return ($change > 0 ? 'Up' : 'Down').' '.$this->figure(abs($change), $quote)
            .' ('.number_format(abs((float) $percent), 2).'%) since '.$since->format('d M').'.';
    }

    /**
     * Points for a 120 by 36 sparkline. A flat market sits on the middle line instead
     * of dividing by a zero range.
     */
    private function points(Collection $history): string
    {
        $values = $history->map(fn ($reading) => (float) $reading->rate)->values();
        $count = $values->count();
        $minimum = (float) $values->min();
        $range = (float) $values->max() - $minimum;

        return $values->map(function (float $value, int $index) use ($count, $minimum, $range): string {
            $x = $count === 1 ? 60 : ($index / ($count - 1)) * 120;
            $y = $range == 0.0 ? 18 : 30 - (($value - $minimum) / $range) * 24;

            return round($x, 2).','.round($y, 2);
        })->implode(' ');
    }
}

<?php

namespace App\View\Components;

use App\Models\ExchangeRate;
use App\Models\RateSnapshot;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\View\Component;

class LiveRateCard extends Component
{
    /**
     * The card carries two lines and nothing else: the price the desk books at, then the market it
     * is standing on. It used to be a tile per pair with a sparkline and a paragraph of change under
     * each, which spent a screen of the board on figures a desk reads in a second.
     */
    public function render(): View
    {
        $user = auth()->user();
        // The live dollar reading is a market figure, not the counter price. It stays with the
        // Super Admin and the lead agent; everyone else sees only the rate the desk sells at.
        $showMarket = $user !== null
            && ($user->hasRole('superadmin') || $user->hasRole('super-agent'));

        $desk = [];
        $market = [];

        foreach (['UGX', 'RMB'] as $quote) {
            $row = ExchangeRate::getActiveRate('USD', $quote);
            $sell = $row ? (float) ($row->sell_rate ?: $row->rate) : null;

            $desk[$quote] = $sell === null || $sell <= 0 ? null : $this->figure($sell, $quote);

            if (! $showMarket) {
                continue;
            }

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

            $market[$quote] = [
                'value' => $this->figure((float) $latest->rate, $quote),
                // A card with one reading on it has nothing to compare the latest to, and saying so
                // twice on one line is noise rather than information. The clause is simply absent
                // until there is a second day to move from.
                'change' => $previous ? $this->changeText($change, $percent, $previous->rate_date) : null,
                'source' => $latest->source,
            ];
        }

        // The two readings can come from different feeds when the first one fails, so the footnote
        // names every feed that supplied a figure rather than attributing both to one.
        $sources = collect($market)->pluck('source')->unique()->values()->all();

        $lastPulled = $showMarket ? RateSnapshot::lastPulledAt() : null;

        // Hourly pulls mean anything older than a day and a bit is a pull that did not run, not a
        // quiet market. The badge says how long ago the last one landed, because "stale" on its own
        // cannot be told apart from a feed that is simply slow to publish.
        $stale = $showMarket && ($lastPulled === null || $lastPulled->lt(now()->subHours(26)));
        $staleSince = $lastPulled === null ? null : $lastPulled->diffForHumans(['parts' => 1]);

        return view('components.live-rate-card', compact(
            'desk', 'market', 'lastPulled', 'sources', 'stale', 'staleSince', 'showMarket'
        ));
    }

    private function figure(float $value, string $quote): string
    {
        $decimals = $quote === 'UGX' ? 2 : 4;

        return rtrim(rtrim(number_format($value, $decimals, '.', ','), '0'), '.');
    }

    /**
     * One clause, not a sentence: the figure and the day it moved from, which is all the grey line
     * beside the market reading has room for.
     */
    private function changeText(?float $change, ?float $percent, Carbon $since): string
    {
        if ($change === null || abs($change) < 0.0000005) {
            return 'unchanged since '.$since->format('j M');
        }

        return ($change > 0 ? 'up ' : 'down ').rtrim(rtrim(number_format(abs($change), 2, '.', ''), '0'), '.')
            .' ('.number_format(abs((float) $percent), 2).'%), '.$since->format('j M');
    }
}

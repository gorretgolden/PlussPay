<?php

namespace App\Support;

use App\Models\RateSnapshot;

class MarketBoard
{
    /**
     * The live reading for both desk quotes, their move since the previous market
     * day, the sparkline points and the recorded days. Shared by System Settings
     * and the Exchange Rates page so both print the same figures.
     *
     * @return array{pairs: array<string, array<string, mixed>>, history: array<int, array<string, mixed>>, last_pulled: ?\Illuminate\Support\Carbon, stale_pull: bool}
     */
    public static function readings(): array
    {
        $pairs = [];
        $histories = [];

        foreach (['UGX', 'RMB'] as $quote) {
            $latest = RateSnapshot::latestFor('USD', $quote);
            $history = RateSnapshot::dailyCloses('USD', $quote, 30);
            $histories[$quote] = $history;
            $previous = $history->count() > 1 ? $history[$history->count() - 2] : null;
            $change = $latest && $previous ? (float) $latest->rate - (float) $previous->rate : null;
            $percent = $change !== null && (float) $previous->rate > 0
                ? ($change / (float) $previous->rate) * 100
                : null;
            $rising = $change !== null && $change > 0;
            $falling = $change !== null && $change < 0;

            $pairs[$quote] = [
                'label' => $quote === 'UGX' ? 'Dollar to shilling' : 'Dollar to yuan',
                'unit' => $quote.' per USD',
                'latest' => $latest,
                'display' => $latest ? static::figure((float) $latest->rate, $quote) : null,
                'previous' => $previous,
                'change' => $change,
                'percent' => $percent,
                'change_text' => static::changeText($change, $percent, $previous, $quote),
                'tone' => $rising ? 'text-green-700' : ($falling ? 'text-red-700' : 'text-gray-500'),
                'line' => $rising ? 'text-green-700' : ($falling ? 'text-red-700' : 'text-gray-400'),
                'points' => $history->count() > 1 ? static::sparkPoints($history) : null,
                'chart_label' => $history->count() > 1 && $latest
                    ? 'USD to '.$quote.' from '.static::figure((float) $history->first()->rate, $quote)
                        .' on '.$history->first()->rate_date->format('d M').' to '
                        .static::figure((float) $latest->rate, $quote).' on '.$latest->rate_date->format('d M').'. '
                        .static::changeText($change, $percent, $previous, $quote)
                    : '',
            ];
        }

        $lastPulled = RateSnapshot::lastPulledAt();

        return [
            'pairs' => $pairs,
            'history' => static::history($histories),
            'last_pulled' => $lastPulled,
            'stale_pull' => $lastPulled !== null && $lastPulled->lt(now()->subHours(26)),
        ];
    }

    public static function changeText(?float $change, ?float $percent, ?RateSnapshot $previous, string $quote): string
    {
        if ($previous === null) {
            return 'First reading recorded. The day to day comparison starts with the next reading.';
        }

        if ($change === null || abs($change) < 0.0000005) {
            return 'Unchanged since '.$previous->rate_date->format('d M').'.';
        }

        return ($change > 0 ? 'Increased' : 'Decreased').' by '
            .static::figure(abs($change), $quote).' ('.number_format(abs((float) $percent), 2).'%) since '
            .$previous->rate_date->format('d M').'.';
    }

    /**
     * Points for a 120 by 36 sparkline. A flat market sits on the middle line instead
     * of dividing by a zero range.
     */
    public static function sparkPoints($history): string
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

    /**
     * The last recorded reading per market day, with both pairs on one line.
     */
    public static function history(array $histories, int $days = 14): array
    {
        $grouped = [];

        foreach ($histories as $quote => $readings) {
            foreach ($readings as $reading) {
                $key = $reading->rate_date->toDateString();
                $grouped[$key] ??= [
                    'date' => $reading->rate_date,
                    'UGX' => '-',
                    'RMB' => '-',
                    'pulled' => $reading->fetched_at,
                ];
                $grouped[$key][$quote] = static::figure((float) $reading->rate, $quote);

                if ($reading->fetched_at->gt($grouped[$key]['pulled'])) {
                    $grouped[$key]['pulled'] = $reading->fetched_at;
                }
            }
        }

        krsort($grouped);

        $list = array_values(array_slice($grouped, 0, $days));

        // Newest first, so the row after each day is its previous market day.
        foreach ($list as $index => $day) {
            $list[$index]['move'] = static::move($day, $list[$index + 1] ?? null);
        }

        return $list;
    }

    /**
     * The change from the market day before, or null where either side has no
     * reading. 'flat' means the price did not move.
     *
     * @param  array{UGX: string, RMB: string}  $day
     * @param  array{UGX: string, RMB: string}|null  $previous
     * @return array{UGX: ?string, RMB: ?string}
     */
    public static function move(array $day, ?array $previous): array
    {
        $move = ['UGX' => null, 'RMB' => null];

        if ($previous === null) {
            return $move;
        }

        foreach (['UGX', 'RMB'] as $quote) {
            if ($day[$quote] === '-' || $previous[$quote] === '-') {
                continue;
            }

            $change = (float) str_replace(',', '', $day[$quote]) - (float) str_replace(',', '', $previous[$quote]);

            if ($change == 0.0) {
                $move[$quote] = 'flat';

                continue;
            }

            $move[$quote] = ($change > 0 ? '+' : '-')
                .($quote === 'UGX'
                    ? number_format(abs($change), 2)
                    : rtrim(rtrim(number_format(abs($change), 4, '.', ''), '0'), '.'));
        }

        return $move;
    }

    public static function figure(float $value, string $quote): string
    {
        return $quote === 'UGX'
            ? number_format($value, 2)
            : rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}

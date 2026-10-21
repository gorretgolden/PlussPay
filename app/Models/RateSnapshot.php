<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RateSnapshot extends Model
{
    protected $fillable = [
        'base_currency',
        'quote_currency',
        'rate',
        'rate_date',
        'source',
        'fetched_at',
    ];

    protected $casts = [
        'rate' => 'decimal:8',
        'rate_date' => 'date',
        'fetched_at' => 'datetime',
    ];

    public static function latestFor(string $base, string $quote): ?self
    {
        return static::query()
            ->where('base_currency', strtoupper($base))
            ->where('quote_currency', strtoupper($quote))
            ->orderByDesc('rate_date')
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * One reading per market day, the last one taken. Hourly pulls of the same daily
     * figure would otherwise draw a flat line and hide the movement she wants to see.
     */
    public static function dailyCloses(string $base, string $quote, int $days = 30): Collection
    {
        return static::query()
            ->where('base_currency', strtoupper($base))
            ->where('quote_currency', strtoupper($quote))
            ->whereDate('rate_date', '>=', today()->subDays(max($days - 1, 0))->toDateString())
            ->orderBy('rate_date')
            ->orderBy('fetched_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (self $snapshot) => $snapshot->rate_date->toDateString())
            ->map(fn ($readings) => $readings->last())
            ->values();
    }

    public static function lastPulledAt(): ?Carbon
    {
        $value = static::query()->max('fetched_at');

        return $value === null ? null : Carbon::parse($value);
    }
}

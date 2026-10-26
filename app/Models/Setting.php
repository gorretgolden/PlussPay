<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /**
     * Clock zones offered in System Settings. None of these observe daylight saving,
     * so the offset printed next to each label stays correct all year.
     */
    public const TIMEZONES = [
        'Africa/Kampala' => 'Kampala, Uganda (UTC +3)',
        'Africa/Nairobi' => 'Nairobi, Kenya (UTC +3)',
        'Africa/Lagos' => 'Lagos, Nigeria (UTC +1)',
        'Asia/Dubai' => 'Dubai, UAE (UTC +4)',
        'Asia/Shanghai' => 'Shanghai, China (UTC +8)',
        'UTC' => 'UTC (no offset)',
    ];

    protected $fillable = ['key', 'value', 'type', 'group', 'label'];

    /**
     * The zone the clocks should run on. A missing or unrecognised value, or a database
     * that is not migrated yet, falls back to the default in config/app.php.
     */
    public static function timezone(): string
    {
        $configured = (string) static::query()->where('key', 'timezone')->value('value');

        return array_key_exists($configured, static::TIMEZONES)
            ? $configured
            : (string) config('app.timezone', 'UTC');
    }

    public static function get(string $key, $default = null)
    {
        $setting = static::where('key', $key)->first();

        if (!$setting) {
            return $default;
        }

        return match ($setting->type) {
            'boolean' => (bool) $setting->value,
            'integer' => (int) $setting->value,
            'float' => (float) $setting->value,
            'array' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    public static function set(string $key, $value, string $type = 'string', string $group = 'general', ?string $label = null): void
    {
        $value = match ($type) {
            'array' => json_encode($value),
            'boolean' => $value ? '1' : '0',
            default => (string) $value,
        };

        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => $type, 'group' => $group, 'label' => $label]
        );
    }

    public static function forget(string $key): void
    {
        static::where('key', $key)->delete();
    }

    /**
     * The smallest and largest booking per intake currency, each measured in that
     * currency's own unit, so a shilling cap never silently limits a dollar booking
     * or the other way round. A desk still holding the old single min/max (before the
     * per-currency split) has those figures read as its shilling pair, because
     * shillings are the money the desk prices in.
     *
     * @return array<string, array{min: float, max: float}>
     */
    public static function transactionBounds(): array
    {
        $defaults = ['min' => 100.0, 'max' => 100000000.0];

        $bound = fn (string $currency, ?float $legacyMin, ?float $legacyMax) => [
            'min' => (float) (static::get('min_transaction_'.strtolower($currency), $legacyMin ?? $defaults['min']) ?? $defaults['min']),
            'max' => (float) (static::get('max_transaction_'.strtolower($currency), $legacyMax ?? $defaults['max']) ?? $defaults['max']),
        ];

        $legacyMin = static::get('min_transaction_amount');
        $legacyMax = static::get('max_transaction_amount');

        return [
            'UGX' => $bound('UGX', $legacyMin, $legacyMax),
            'USD' => $bound('USD', null, null),
            'RMB' => $bound('RMB', null, null),
        ];
    }
}

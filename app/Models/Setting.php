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
}

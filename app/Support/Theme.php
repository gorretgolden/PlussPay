<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The single accent colour the whole app wears. Tailwind's green palette is rebuilt
 * from that one base at render time, so every green utility in every view (sidebar,
 * buttons, cards, badges) follows the theme; the CSS variables cover the hand written
 * rules and the sign-in page.
 */
class Theme
{
    public const DEFAULT_COLOR = '#166534';

    /** Mix ratios away from the base: white toward the light end, black toward the dark end. */
    private const SHADES = [
        50 => ['#ffffff', 0.95],
        100 => ['#ffffff', 0.90],
        200 => ['#ffffff', 0.78],
        300 => ['#ffffff', 0.60],
        400 => ['#ffffff', 0.40],
        500 => ['#ffffff', 0.25],
        600 => ['#000000', 0.12],
        700 => ['#000000', 0.08],
        800 => [null, 0.0],
        900 => ['#000000', 0.18],
        950 => ['#000000', 0.36],
    ];

    public static function color(): string
    {
        // login_theme_color is what the older login-only picker saved; it still reads
        // as the chosen base so an upgrade keeps the colour the office already picked.
        return self::normalize(Setting::get('theme_color'))
            ?? self::normalize(Setting::get('login_theme_color'))
            ?? self::DEFAULT_COLOR;
    }

    public static function normalize(?string $color): ?string
    {
        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color)
            ? strtolower($color)
            : null;
    }

    /** @return array<int, string> */
    public static function palette(?string $color = null): array
    {
        $base = self::normalize($color) ?? self::color();
        [$red, $green, $blue] = sscanf($base, '#%02x%02x%02x');

        $palette = [];
        foreach (self::SHADES as $shade => [$toward, $ratio]) {
            $palette[$shade] = $toward === null
                ? $base
                : self::mix([$red, $green, $blue], $toward, $ratio);
        }

        return $palette;
    }

    /**
     * Style and script tags for a layout head. The palette script has to come after the
     * Tailwind CDN tag so the generated utilities pick the shades up.
     */
    public static function head(): string
    {
        $color = self::color();
        $palette = self::palette($color);

        $variables = '--brand: '.$color.'; --brand-soft: '.$color.'4D; --brand-tint: '.$color.'1A;';
        $shades = [];
        foreach ($palette as $shade => $hex) {
            $variables .= ' --brand-'.$shade.': '.$hex.';';
            $shades[] = $shade.': '.json_encode($hex);
        }

        return '<style>:root { '.$variables.' }</style>'
            .'<script>tailwind.config = { theme: { extend: { colors: { green: { '.implode(', ', $shades).' } } } } };</script>';
    }

    private static function mix(array $rgb, string $toward, float $ratio): string
    {
        [$toRed, $toGreen, $toBlue] = sscanf($toward, '#%02x%02x%02x');
        $blend = static fn (int $from, int $to): int => (int) round($from + ($to - $from) * $ratio);

        return sprintf('#%02x%02x%02x', $blend($rgb[0], $toRed), $blend($rgb[1], $toGreen), $blend($rgb[2], $toBlue));
    }
}

<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Which picture stands behind the sign-in card: one of the bundled designs or the
 * office's own upload. The uploaded file wins only while it is the chosen source,
 * so picking a bundled design later does not leave half a setting behind.
 */
class LoginBackground
{
    public const BUILT_IN = [
        'default' => ['label' => 'Night skyline', 'file' => 'login-background.webp'],
        'waves' => ['label' => 'Emerald waves', 'file' => 'login-backgrounds/waves.svg'],
        'paper' => ['label' => 'Linen and ink', 'file' => 'login-backgrounds/paper.svg'],
        'glass' => ['label' => 'Forest glass', 'file' => 'login-backgrounds/glass.svg'],
    ];

    public static function choice(): string
    {
        $choice = Setting::get('login_background_style');

        if ($choice === 'upload') {
            return 'upload';
        }

        if (is_string($choice) && isset(self::BUILT_IN[$choice])) {
            return $choice;
        }

        // Rows saved before the picker existed: an upload wins, then the bundled default.
        return Setting::get('login_background') ? 'upload' : 'default';
    }

    public static function url(): string
    {
        $choice = self::choice();

        if ($choice === 'upload') {
            $uploaded = Setting::get('login_background');

            if ($uploaded) {
                return Media::url($uploaded, 'login-background.webp');
            }

            $choice = 'default';
        }

        return asset(self::BUILT_IN[$choice]['file']);
    }

    /** Path of the bundled file for a choice, so the settings tiles can show it. */
    public static function file(string $choice): string
    {
        return self::BUILT_IN[$choice]['file'] ?? self::BUILT_IN['default']['file'];
    }
}

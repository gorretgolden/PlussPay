<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

class Branding
{
    /**
     * Absolute path of the logo uploaded in System Settings, falling back to the
     * logo shipped with the application. Null when neither file can be read.
     */
    public static function logoPath(): ?string
    {
        $path = Setting::get('login_logo');

        if ($path && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->path($path);
        }

        $fallback = public_path('logo.webp');

        return is_file($fallback) ? $fallback : null;
    }

    /**
     * The logo as a data URI, so a PDF needs no file permissions or web request
     * to show it.
     */
    public static function logoDataUri(): ?string
    {
        $path = static::logoPath();

        if ($path === null) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        $mime = @mime_content_type($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    public static function companyName(): string
    {
        return (string) (Setting::get('company_name') ?: 'Plus Save Financial Solutions');
    }
}

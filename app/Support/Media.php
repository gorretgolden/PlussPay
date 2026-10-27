<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class Media
{
    /**
     * Public URL for a stored image, stamped with its last-modified time. Uploads keep
     * the same path on purpose (login/background.png), so without the stamp the browser
     * would show the previous picture from its cache after a replacement.
     */
    public static function url(?string $path, string $fallback = ''): string
    {
        $disk = Storage::disk('public');

        if (! $path || ! $disk->exists($path)) {
            return asset($fallback);
        }

        return asset('storage/'.$path).'?v='.$disk->lastModified($path);
    }
}

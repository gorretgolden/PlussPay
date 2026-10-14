<?php

namespace App\Support;

/**
 * Turns a raw User-Agent header into the two short labels the login log shows.
 * Deliberately tiny and dependency free: it only needs to tell a phone from a
 * desktop and name the browser, not to be a full device database.
 */
class UserAgent
{
    public static function browser(?string $ua): ?string
    {
        $ua = (string) $ua;

        // Order matters: Edge and Brave both report Chrome, and Chrome reports Safari.
        $pipes = [
            'Edge' => '/\bEdg(?:e|A|iOS)?\/([\d.]+)/i',
            'OPR' => '/\bOPR\/([\d.]+)/i',
            'Brave' => '/\bBrave/i',
            'Chrome' => '/\b(?:Chrome|CriOS)\/([\d.]+)/i',
            'Firefox' => '/\b(?:Firefox|FxiOS)\/([\d.]+)/i',
            'Safari' => '/\bVersion\/[\d.]+.*\bSafari/i',
            'Internet Explorer' => '/\bMSIE|\bTrident/i',
        ];

        foreach ($pipes as $name => $pattern) {
            if (preg_match($pattern, $ua)) {
                return $name === 'OPR' ? 'Opera' : $name;
            }
        }

        if (stripos($ua, 'bot') !== false || stripos($ua, 'spider') !== false) {
            return 'Bot';
        }

        return null;
    }

    public static function device(?string $ua): ?string
    {
        $ua = (string) $ua;

        if (preg_match('/\b(iPad|Tablet|PlayBook)\b/i', $ua)) {
            return 'Tablet';
        }

        if (preg_match('/\b(iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|Opera Mini|Mobile)\b/i', $ua)) {
            return 'Mobile';
        }

        if (preg_match('/\b(Android|Silk|IEMobile)\b/i', $ua)) {
            return 'Tablet';
        }

        if (trim($ua) === '') {
            return null;
        }

        return 'Desktop';
    }
}

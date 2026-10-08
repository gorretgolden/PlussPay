<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;

class SystemLogReader
{
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    public const WINDOWS = [2 => 'Last 2 MB', 10 => 'Last 10 MB', 50 => 'Last 50 MB'];

    private const MAX_EXPORT_ROWS = 5000;

    /**
     * Where Laravel actually writes logs, so the page follows a move from the
     * single file to daily files without a code change.
     */
    public static function directory(): string
    {
        $default = (string) config('logging.default');
        $path = config("logging.channels.$default.path");

        foreach ((array) config("logging.channels.$default.channels", []) as $channel) {
            if (is_string($channel) && ($candidate = config("logging.channels.$channel.path"))) {
                $path = $candidate;
                break;
            }
        }

        return $path ? dirname((string) $path) : storage_path('logs');
    }

    /**
     * Log files in storage/logs, the one written to most recently first.
     */
    public static function files(): array
    {
        $found = glob(self::directory() . '/laravel*.log') ?: [];

        return collect($found)
            ->filter(fn ($path) => (bool) self::parseName(basename($path)))
            ->map(fn ($path) => [
                'name' => basename($path),
                'bytes' => (int) filesize($path),
                'written_at' => (int) filemtime($path),
                'written' => date('M d, Y h:i A', (int) filemtime($path)),
            ])
            ->sortByDesc('written_at')
            ->values()
            ->all();
    }

    public static function defaultFile(): ?string
    {
        return self::files()[0]['name'] ?? null;
    }

    /**
     * Read the tail of one log file and split it into entries, newest first.
     *
     * The whole file is never loaded: a production log can run to hundreds of
     * megabytes, and the entries she needs are always at the end.
     */
    public static function read(?string $name, int $megabytes): array
    {
        $meta = [
            'file' => null,
            'size' => 0,
            'read' => 0,
            'truncated' => false,
            'entries' => [],
        ];

        $path = self::resolve($name);

        if (! $path) {
            return $meta;
        }

        $size = (int) filesize($path);
        $window = max(1, $megabytes) * 1000000;
        $start = max(0, $size - $window);

        $handle = fopen($path, 'rb');

        if (! $handle) {
            return $meta;
        }

        fseek($handle, $start);

        // The first line read is almost always cut mid entry.
        if ($start > 0) {
            fgets($handle);
        }

        $entries = [];
        $current = null;

        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(\w+)\.([A-Za-z]+):\s?(.*)$/s', $line, $m)) {
                if ($current) {
                    $entries[] = self::build($current);
                }
                $current = [
                    'time' => $m[1],
                    'channel' => $m[2],
                    'level' => strtoupper($m[3]),
                    'lines' => [rtrim($m[4], "\r\n")],
                ];
            } elseif ($current) {
                $current['lines'][] = rtrim($line, "\r\n");
            }
        }

        fclose($handle);

        if ($current) {
            $entries[] = self::build($current);
        }

        $meta['file'] = basename($path);
        $meta['size'] = $size;
        $meta['read'] = $size - $start;
        $meta['truncated'] = $start > 0;
        $meta['entries'] = array_reverse($entries);

        return $meta;
    }

    /**
     * The requested read window, falling back to the smallest one.
     */
    public static function window(Request $request): int
    {
        $megabytes = (int) $request->input('window', 2);

        return array_key_exists($megabytes, self::WINDOWS) ? $megabytes : 2;
    }

    /**
     * Apply the page filters. Levels, dates and text all narrow the same set
     * the table shows, so the export always matches what is on screen.
     */
    public static function filter(array $entries, Request $request): array
    {
        $level = strtolower((string) $request->input('level'));
        $search = trim((string) $request->input('search'));
        $searchLower = strtolower($search);
        $from = $request->filled('date_from') ? Carbon::parse($request->input('date_from'))->startOfDay() : null;
        $to = $request->filled('date_to') ? Carbon::parse($request->input('date_to'))->endOfDay() : null;

        if ($search === '' && $level === '' && ! $from && ! $to) {
            return $entries;
        }

        return array_values(array_filter($entries, function (array $entry) use ($level, $searchLower, $from, $to) {
            if ($level && strtolower($entry['level']) !== $level) {
                return false;
            }

            if ($from && $entry['moment']->lt($from)) {
                return false;
            }

            if ($to && $entry['moment']->gt($to)) {
                return false;
            }

            if ($searchLower !== '' && ! str_contains(strtolower($entry['message'] . ' ' . $entry['where'] . ' ' . $entry['stack']), $searchLower)) {
                return false;
            }

            return true;
        }));
    }

    public static function countsByLevel(array $entries): array
    {
        $counts = array_fill_keys(self::LEVELS, 0);

        foreach ($entries as $entry) {
            $key = strtolower($entry['level']);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    public static function exportRows(array $entries): array
    {
        return array_map(fn (array $entry) => [
            $entry['moment']->format('Y-m-d h:i A'),
            $entry['level'],
            mb_substr(preg_replace('/\s+/', ' ', $entry['message']), 0, 500),
            $entry['where'] ?: '-',
            mb_substr(preg_replace('/\s+/', ' ', $entry['stack']), 0, 900) ?: '-',
        ], array_slice($entries, 0, self::MAX_EXPORT_ROWS));
    }

    public static function exportHeaders(): array
    {
        return ['Date', 'Level', 'Message', 'Where', 'Stack Trace'];
    }

    /**
     * Only real Laravel log files inside storage/logs can be opened.
     */
    private static function resolve(?string $name): ?string
    {
        $dir = self::directory();

        if (! $name || $name === 'latest' || ! self::parseName($name)) {
            $latest = self::defaultFile();

            return $latest ? $dir . DIRECTORY_SEPARATOR . $latest : null;
        }

        $path = realpath($dir . DIRECTORY_SEPARATOR . $name);

        if (! $path || ! is_file($path) || ! str_starts_with($path, (string) realpath($dir))) {
            $latest = self::defaultFile();

            return $latest ? $dir . DIRECTORY_SEPARATOR . $latest : null;
        }

        return $path;
    }

    private static function parseName(string $name): bool
    {
        return (bool) preg_match('/^laravel(-\d{4}_\d{2}_\d{2})?\.log$/', $name);
    }

    private static function build(array $raw): array
    {
        $lines = $raw['lines'];
        $text = implode("\n", $lines);
        $rest = implode("\n", array_slice($lines, 1));

        $message = $text;
        $context = null;

        // The context block is last, but a message with a line break inside it
        // pushes the opening brace onto a continuation line.
        $position = strpos($text, ' {"');

        if ($position !== false) {
            $candidate = substr($text, $position + 1);
            $decoded = json_decode($candidate, true);

            // Monolog writes the stack trace into the context with real line
            // breaks, which is not valid JSON until they are escaped again.
            if (! is_array($decoded)) {
                $decoded = json_decode(str_replace(["\r\n", "\n", "\r", "\t"], '\n', $candidate), true);
            }

            // A trace that is not valid JSON at all is still worth showing, so
            // keep the raw tail rather than dropping it with the message.
            if (is_array($decoded)) {
                $message = rtrim(substr($text, 0, $position));
                $context = $decoded;
            }
        }

        $stack = is_array($context) ? (string) ($context['exception'] ?? '') : '';

        if ($stack === '' && $rest !== '') {
            $stack = $rest;
        }

        if ($stack === '' && is_array($context)) {
            $stack = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if (preg_match('/ at \s*(.*?\.php)(?::|\s+line\s+)(\d+)/i', $stack, $m)) {
            $where = $m[1] . ':' . $m[2];
        } else {
            preg_match('/(?:at|in)\s+([^\s"\\\\]+\.(?:php|js))(?::|\s+line\s+)(\d+)/i', $stack, $m);
            $where = isset($m[1]) ? $m[1] . ':' . $m[2] : '';
        }

        return [
            'moment' => Carbon::parse($raw['time']),
            'time' => $raw['time'],
            'channel' => $raw['channel'],
            'level' => $raw['level'],
            'message' => $message,
            'where' => $where,
            'stack' => $stack,
            'context' => is_array($context) ? $context : [],
        ];
    }
}

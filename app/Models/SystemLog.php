<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

class SystemLog extends Model
{
    public const UPDATED_AT = null;

    public const LEVELS = ['critical', 'error', 'warning'];

    protected $fillable = [
        'level',
        'message',
        'exception_class',
        'file',
        'line',
        'trace',
        'url',
        'method',
        'user_id',
        'ip_address',
        'resolved',
        'resolved_by',
        'resolved_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'resolved' => 'boolean',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Persist one unhandled throwable into the queryable error table. This runs from
     * the exception handler, so it must never throw: a logging failure on top of an
     * already-failing request would replace the real error page with a white screen.
     * laravel.log keeps receiving everything regardless, this is only the searchable copy.
     */
    public static function recordThrowable(Throwable $e, ?Request $request = null, string $level = 'error'): ?self
    {
        try {
            $previous = $e->getPrevious();

            return static::create([
                'level' => $level,
                'message' => mb_substr($e->getMessage() ?: static::classOf($e), 0, 2000),
                'exception_class' => static::classOf($e),
                'file' => mb_substr($e->getFile(), 0, 512),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
                    . ($previous ? "\nCaused by: " . static::classOf($previous) . ': ' . $previous->getMessage()
                        . "\n" . $previous->getTraceAsString() : ''),
                'url' => $request ? mb_substr($request->fullUrl(), 0, 2048) : null,
                'method' => $request?->method(),
                'user_id' => $request && $request->user() ? $request->user()->id : null,
                'ip_address' => $request?->ip(),
            ]);
        } catch (Throwable) {
            // Losing the copy beats breaking the response.
            return null;
        }
    }

    public static function levelFor(Throwable $e): string
    {
        return match (true) {
            $e instanceof \Illuminate\Database\QueryException => 'critical',
            default => 'error',
        };
    }

    private static function classOf(Throwable $e): string
    {
        return mb_substr($e::class, 0, 255);
    }

    public function scopeFiltered(Builder $query, Request $request): Builder
    {
        foreach (['level'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->has('resolved') && $request->input('resolved') !== '') {
            $query->where('resolved', $request->boolean('resolved'));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('message', 'like', "%{$search}%")
                    ->orWhere('exception_class', 'like', "%{$search}%")
                    ->orWhere('file', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return $query;
    }

    public static function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('resolved', false);
    }
}

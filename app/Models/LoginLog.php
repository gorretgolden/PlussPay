<?php

namespace App\Models;

use App\Support\UserAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class LoginLog extends Model
{
    public const UPDATED_AT = null;

    public const STATUSES = [
        'success' => 'Success',
        'failed' => 'Failed',
        'logout' => 'Logout',
        'locked' => 'Locked',
    ];

    protected $fillable = [
        'user_id',
        'email_attempted',
        'status',
        'ip_address',
        'user_agent',
        'device',
        'browser',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /**
     * One row per authentication outcome. The email is kept even when no account
     * matches, because a run of unknown addresses is how stuffing reads.
     */
    public static function record(Request $request, string $status, ?User $user = null, ?string $attemptedEmail = null): void
    {
        $agent = $request->userAgent();

        static::create([
            'user_id' => $user?->id,
            'email_attempted' => mb_substr(strtolower((string) ($user?->email ?? $attemptedEmail ?? '')), 0, 191) ?: null,
            'status' => $status,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $agent, 0, 512) ?: null,
            'device' => UserAgent::device($agent),
            'browser' => UserAgent::browser($agent),
        ]);
    }

    /**
     * Addresses and accounts with at least $threshold failed attempts inside the
     * window. Both are returned so the badge can name whichever side is being hit.
     *
     * @return array{ips: \Illuminate\Support\Collection, emails: \Illuminate\Support\Collection}
     */
    public static function suspiciousSince(int $minutes = 15, int $threshold = 5): array
    {
        $since = now()->subMinutes($minutes);

        $group = function (string $column) use ($since, $threshold) {
            return static::query()
                ->where('status', 'failed')
                ->where('created_at', '>=', $since)
                ->whereNotNull($column)
                ->selectRaw("{$column} as label, COUNT(*) as attempts")
                ->groupBy($column)
                ->havingRaw('COUNT(*) >= ?', [$threshold])
                ->orderByDesc('attempts')
                ->get()
                ->pluck('attempts', 'label');
        };

        return [
            'ips' => $group('ip_address'),
            'emails' => $group('email_attempted'),
        ];
    }

    public function scopeFiltered(Builder $query, Request $request): Builder
    {
        foreach (['status', 'user_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('ip_address')) {
            $query->where('ip_address', $request->input('ip_address'));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('ip_address', 'like', "%{$search}%")
                    ->orWhere('email_attempted', 'like', "%{$search}%")
                    ->orWhere('browser', 'like', "%{$search}%")
                    ->orWhere('device', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
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
}

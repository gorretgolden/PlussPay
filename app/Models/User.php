<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password', 'is_active', 'is_locked', 'failed_login_attempts', 'locked_until', 'last_login_at', 'last_login_ip', 'allowed_days', 'allowed_from', 'allowed_to', 'two_factor_secret', 'two_factor_enabled'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    public const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'failed_login_attempts' => 'integer',
            'two_factor_enabled' => 'boolean',
            'two_factor_secret' => 'encrypted',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'created_by');
    }

    /**
     * Names are always stored as "Gorret Golden" so they print correctly on
     * receipts and audit entries whatever the teller typed.
     */
    public function setNameAttribute($value): void
    {
        $name = preg_replace('/\s+/', ' ', trim((string) $value));

        $this->attributes['name'] = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }

    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = self::normalizePhone($value);
    }

    /**
     * Addresses are stored lowercased so that Gorret@PFS.com and gorret@pfs.com are
     * one account, not two rows that only MySQL's case-insensitive collation saves
     * from colliding. Sign-in lowercases what is typed, so capitals still work.
     */
    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = strtolower(trim((string) $value));
    }

    /**
     * Rewrites local and repeated-country-code numbers into one comparable form
     * so "0751 657 654", "0751657654" and "+2560751657654" cannot both exist.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '00')) {
            $digits = ltrim($digits, '0');
        }

        if (str_starts_with($digits, '2560')) {
            $digits = '256' . ltrim(substr($digits, 3), '0');
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '256' . substr($digits, 1);
        }

        return '+' . $digits;
    }

    public function scopeFiltered($query, Request $request)
    {
        if ($request->filled('search')) {
            $search = '%' . addcslashes($request->search, '%_\\') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('phone', 'like', $search);
            });
        }

        if ($request->filled('role')) {
            $query->role($request->role);
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        if ($request->filled('lock')) {
            $query->where('is_locked', $request->lock === 'locked');
        }

        if ($request->filled('two_factor')) {
            $query->where('two_factor_enabled', $request->two_factor === 'on');
        }

        if ($request->filled('access')) {
            $restricted = $request->access === 'restricted';

            $query->where(function ($q) use ($restricted) {
                foreach (['allowed_days', 'allowed_from', 'allowed_to'] as $column) {
                    $restricted ? $q->orWhereNotNull($column) : $q->whereNull($column);
                }
            });
        }

        return $query;
    }

    public function isLocked(): bool
    {
        if ($this->is_locked) {
            return true;
        }

        if ($this->locked_until && $this->locked_until->isFuture()) {
            return true;
        }

        return false;
    }

    public function recordFailedLogin(): void
    {
        $maxAttempts = (int) \App\Models\Setting::get('max_login_attempts', 5);
        $lockoutDuration = (int) \App\Models\Setting::get('lockout_duration_minutes', 15);

        $this->increment('failed_login_attempts');

        if ($this->failed_login_attempts >= $maxAttempts) {
            $this->update([
                'locked_until' => now()->addMinutes($lockoutDuration),
            ]);
        }
    }

    public function recordSuccessfulLogin(?string $ip = null): void
    {
        $this->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);
    }

    public function unlock(): void
    {
        $this->update([
            'is_locked' => false,
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);
    }

    public function allowedDayList(): array
    {
        $days = array_filter(array_map('intval', explode(',', (string) $this->allowed_days)));

        return array_values(array_unique($days));
    }

    public function setAllowedDays(array $days): void
    {
        $days = array_values(array_unique(array_filter(array_map('intval', $days), fn($d) => $d >= 1 && $d <= 7)));
        sort($days);

        $this->allowed_days = $days ? implode(',', $days) : null;
    }

    public function hasLoginWindow(): bool
    {
        return $this->allowedDayList() !== [] || $this->allowed_from !== null || $this->allowed_to !== null;
    }

    public function outsideLoginWindow(?Carbon $at = null): bool
    {
        return $this->loginWindowBlockReason($at) !== null;
    }

    /**
     * Null means the account may sign in right now.
     */
    public function loginWindowBlockReason(?Carbon $at = null): ?string
    {
        $at ??= now();

        if (!$this->hasLoginWindow()) {
            return null;
        }

        $reasons = [];
        $days = $this->allowedDayList();

        if ($days && !in_array((int) $at->isoWeekday(), $days, true)) {
            $reasons[] = 'on ' . $this->dayListLabel();
        }

        if (!$this->withinAllowedTime($at->format('H:i'))) {
            $reasons[] = 'between ' . $this->timeLabel();
        }

        if (!$reasons) {
            return null;
        }

        return 'You can only sign in ' . implode(' and ', $reasons) . '.';
    }

    public function loginWindowSummary(): string
    {
        if (!$this->hasLoginWindow()) {
            return 'Any time';
        }

        $parts = [];

        if ($this->allowedDayList()) {
            $parts[] = $this->dayListLabel();
        }

        if ($this->allowed_from || $this->allowed_to) {
            $parts[] = $this->timeLabel();
        }

        return implode(' ', $parts);
    }

    private function withinAllowedTime(string $clock): bool
    {
        $from = $this->allowed_from;
        $to = $this->allowed_to;

        if ($from === null && $to === null) {
            return true;
        }

        if ($from === null) {
            return $clock <= $to;
        }

        if ($to === null) {
            return $clock >= $from;
        }

        // A window that runs past midnight, such as 20:00 to 06:00.
        return $from <= $to
            ? ($clock >= $from && $clock <= $to)
            : ($clock >= $from || $clock <= $to);
    }

    private function timeLabel(): string
    {
        return ($this->allowed_from ?? '00:00') . ' to ' . ($this->allowed_to ?? '23:59');
    }

    private function dayListLabel(): string
    {
        $days = $this->allowedDayList();

        if ($days === range(1, 5)) {
            return 'Monday to Friday';
        }

        if ($days === range(1, 6)) {
            return 'Monday to Saturday';
        }

        return implode(', ', array_map(fn($day) => self::DAY_NAMES[$day - 1], $days));
    }
}

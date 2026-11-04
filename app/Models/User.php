<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'country', 'timezone', 'password', 'password_changed_at', 'is_active', 'is_locked', 'failed_login_attempts', 'locked_until', 'last_login_at', 'last_login_ip', 'allowed_days', 'allowed_from', 'allowed_to', 'two_factor_secret', 'two_factor_enabled', 'must_change_password', 'created_by', 'updated_by'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    public const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /** The day the creator and editor columns were added, named on rows saved before them. */
    public const TRACKING_STARTED_AT = '27 Oct 2026';

    /**
     * Any edit made by a signed-in person is attributed to them. Sign-in tracking
     * skips events on purpose so a login never shows up as the last editor.
     */
    protected static function booted(): void
    {
        static::updating(function (self $user) {
            if (auth()->check() && ! $user->isDirty('updated_by')) {
                $user->updated_by = auth()->id();
            }
        });
    }

    /**
     * The two desks this book runs. The country is what tells a China agent and a
     * Uganda agent apart on screen, and it drives the clock zone they are shown.
     */
    public const COUNTRIES = ['UG' => 'Uganda', 'CN' => 'China'];

    public const COUNTRY_TIMEZONES = ['UG' => 'Africa/Kampala', 'CN' => 'Asia/Shanghai'];

    public const DEFAULT_COUNTRY = 'UG';

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
            'password_changed_at' => 'datetime',
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'failed_login_attempts' => 'integer',
            'two_factor_enabled' => 'boolean',
            'must_change_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'created_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'updated_by');
    }

    /**
     * Plain country name for display. No flag emoji: Windows renders flags as two
     * bare letter boxes, which reads as a bug rather than a country.
     */
    public function countryLabel(): string
    {
        return self::COUNTRIES[$this->country] ?? '';
    }

    public static function timezoneForCountry(?string $country): string
    {
        return self::COUNTRY_TIMEZONES[$country] ?? self::COUNTRY_TIMEZONES[self::DEFAULT_COUNTRY];
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

        if ($request->filled('country')) {
            $query->where('country', $request->country);
        }

        // Blocked is what an admin switched off; locked out is the temporary state that
        // failed passwords set. They are listed together because one action clears either.
        if ($request->filled('status')) {
            if ($request->status === 'inactive') {
                $query->where('is_active', false);
            } elseif ($request->status === 'locked') {
                $query->where(fn ($q) => $q
                    ->where('is_locked', true)
                    ->orWhere('locked_until', '>', now()));
            } else {
                $query->where('is_active', true);
            }
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

    /**
     * Whether this person hands cash up to a lead agent, so whether any screen should offer the
     * pen. An ordinary hand in Uganda collects notes over the counter and owes them to a lead; a
     * lead already holds what the others gave him, and a desk in China pays receivers out of what
     * was sent to it instead of handing anything up. Both the board and the transactions list ask
     * this one question, so a shortcut cannot point at a page that then refuses the button.
     */
    public function mayHandInCash(): bool
    {
        return $this->can('view-wallets')
            && $this->hasRole('agent')
            && ! $this->hasRole('super-agent')
            && ($this->country ?: self::DEFAULT_COUNTRY) !== 'CN';
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
        $this->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->saveQuietly();
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
        $at = ($at ?? now())->copy()->setTimezone($this->timezone ?: self::timezoneForCountry($this->country));

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
        return self::clockLabel($this->allowed_from ?? '00:00') . ' to ' . self::clockLabel($this->allowed_to ?? '23:59');
    }

    /**
     * The desk reads a clock face, not a timetable: a window is written 8:00 AM to
     * 6:00 PM on screen even though the database keeps it as 08:00 and 18:00.
     */
    public static function clockLabel(?string $clock): string
    {
        $clock = self::normalizeClock($clock);

        if ($clock === null) {
            return '';
        }

        $parts = array_map('intval', explode(':', $clock));
        $hour = $parts[0] % 12 ?: 12;
        $minute = str_pad((string) $parts[1], 2, '0', STR_PAD_LEFT);

        return $hour . ':' . $minute . ' ' . ($parts[0] >= 12 ? 'PM' : 'AM');
    }

    /**
     * For a field: a stored 18:00 becomes 6:00 PM, while a value the person just typed is
     * handed back untouched so a mistake is never silently rewritten into another time.
     */
    public static function displayClock(?string $value): string
    {
        $clock = self::normalizeClock($value);

        return $clock === null ? trim((string) $value) : self::clockLabel($clock);
    }

    /**
     * Accepts a clock face (8:00 AM, 6:30pm), a bare hour with AM/PM, or 24-hour time,
     * and returns the one storage shape. Null means the text is not a time at all.
     */
    public static function normalizeClock(?string $value): ?string
    {
        $value = preg_replace('/\s+/', ' ', strtoupper(trim((string) $value)));

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})(?::(\d{1,2}))? ?(AM|PM)$/', $value, $match)) {
            $hour = (int) $match[1];
            $minute = (int) ($match[2] ?? 0);

            if ($hour < 1 || $hour > 12 || $minute > 59) {
                return null;
            }

            $hour = $match[3] === 'AM'
                ? ($hour === 12 ? 0 : $hour)
                : ($hour === 12 ? 12 : $hour + 12);

            return sprintf('%02d:%02d', $hour, $minute);
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $match)) {
            if ((int) $match[1] > 23 || (int) $match[2] > 59) {
                return null;
            }

            return sprintf('%02d:%02d', (int) $match[1], (int) $match[2]);
        }

        return null;
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

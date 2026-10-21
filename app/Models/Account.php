<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class Account extends Model
{
    public const UGX_UGANDA = 'UGX-UG';
    public const USD_UGANDA = 'USD-UG';
    public const USD_CHINA = 'USD-CN';
    public const RMB_CHINA = 'RMB-CN';

    protected $fillable = [
        'code',
        'owner_id',
        'name',
        'country',
        'currency',
        'opening_balance',
        'is_active',
        'description',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(AccountEntry::class)->orderBy('entry_date')->orderBy('id');
    }

    /**
     * Accounts are created by a migration, so look them up by code and rebuild on demand.
     * A production box that skipped the seed must still be able to post entries.
     */
    public static function byCode(string $code): self
    {
        return static::firstOrCreate(
            ['code' => $code],
            ['name' => static::nameFor($code), 'country' => str_ends_with($code, 'UG') ? 'UG' : 'CN',
                'currency' => substr($code, 0, 3), 'is_active' => true]
        );
    }

    private static function nameFor(string $code): string
    {
        return match ($code) {
            self::UGX_UGANDA => 'UGX Cash (Uganda)',
            self::USD_UGANDA => 'Dollar Account (Uganda)',
            self::USD_CHINA => 'Dollar Account (China)',
            self::RMB_CHINA => 'RMB Account (China)',
            default => $code,
        };
    }

    public static function ordered(): array
    {
        $codes = [self::UGX_UGANDA, self::USD_UGANDA, self::USD_CHINA, self::RMB_CHINA];

        foreach ($codes as $code) {
            static::byCode($code);
        }

        return static::whereIn('code', $codes)
            ->get()
            ->sortBy(fn (self $a) => array_search($a->code, $codes))
            ->values()
            ->all();
    }

    /**
     * Money is only ever added in the account's own currency, so the balance is the
     * opening figure plus everything in, minus everything out, up to the given day.
     */
    /**
     * Every day between two dates, inclusive and capped, so a mistyped range cannot build a
     * million rows on one page.
     *
     * @return array<int, string>
     */
    public static function dateRange(string $from, string $to): array
    {
        $dates = [];
        $cursor = strtotime($from);
        $end = strtotime($to);

        if ($cursor === false || $end === false || $end < $cursor) {
            return [];
        }

        while ($cursor <= $end && count($dates) < 400) {
            $dates[] = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);
        }

        return $dates;
    }

    public function balance(?string $asOfDate = null): string
    {
        $in = $this->sumFor('in', $asOfDate);
        $out = $this->sumFor('out', $asOfDate);

        return bcadd(bcsub((string) $this->opening_balance, '0.00', 2), bcsub($in, $out, 2), 2);
    }

    /**
     * When this wallet's balance last changed. Money moves by writing a ledger line, not by
     * touching the account row, so the newest line's write time is the event; updated_at would
     * only say when the wallet was opened.
     */
    public function lastMovementAt(): ?Carbon
    {
        $at = $this->entries()->max('created_at');

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * The figure carried into a day, so a page or export that starts mid-year can still show
     * an honest running balance.
     */
    public function balanceBefore(string $date): string
    {
        $balance = (string) $this->opening_balance;

        foreach ($this->entries()->whereDate('entry_date', '<', $date)->cursor() as $entry) {
            $balance = $entry->direction === 'in'
                ? bcadd($balance, (string) $entry->amount, 2)
                : bcsub($balance, (string) $entry->amount, 2);
        }

        return $balance;
    }

    /**
     * Closing balance for every day in the range, which is the end-of-day figure she writes
     * per account. Days with no movement still get a row so the trail has no gaps.
     *
     * @return array<string, array{in: string, out: string, closing: string}>
     */
    public function dailyClosing(string $from, array $dates): array
    {
        $rows = [];
        $balance = $this->balanceBefore($from);

        $byDay = $this->entries()
            ->whereBetween('entry_date', [$from, end($dates) ?: $from])
            ->get()
            ->groupBy(fn (AccountEntry $entry) => $entry->entry_date->toDateString());

        foreach ($dates as $day) {
            $movement = $byDay->get($day, collect());
            $in = (string) $movement->where('direction', 'in')->sum('amount');
            $out = (string) $movement->where('direction', 'out')->sum('amount');
            $balance = bcadd(bcadd($balance, $in, 2), bcsub('0.00', $out, 2), 2);

            $rows[$day] = ['in' => $in, 'out' => $out, 'closing' => $balance];
        }

        return $rows;
    }

    private function sumFor(string $direction, ?string $asOfDate): string
    {
        $query = $this->entries()->where('direction', $direction);

        if ($asOfDate !== null) {
            $query->whereDate('entry_date', '<=', $asOfDate);
        }

        return (string) ($query->sum('amount') ?? '0');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Money the way she reads it: shillings in whole figures, dollars and yuan to the pesewa.
     * Shillings never carry decimals because the coins are not in circulation, and the figure is
     * always stated in its own currency rather than converted.
     */
    public static function format(?string $currency, float|string|null $value): string
    {
        $currency = strtoupper((string) $currency);
        $figure = number_format((float) $value, $currency === 'UGX' ? 0 : 2);

        return match ($currency) {
            'UGX' => 'UGX '.$figure,
            'USD' => '$'.$figure,
            'RMB' => '¥'.$figure,
            default => trim($currency.' '.$figure),
        };
    }

    /**
     * The name a currency goes by in a sentence, because a card that reads "Spent in UGX" is
     * not something she would ever say out loud.
     */
    public static function currencyLabel(?string $currency): string
    {
        return match (strtoupper((string) $currency)) {
            'UGX' => 'shillings',
            'USD' => 'dollars',
            'RMB' => 'yuan',
            default => (string) $currency,
        };
    }

    public function isWallet(): bool
    {
        return $this->owner_id !== null;
    }

    /**
     * Get or create the wallet account for a given user and currency.
     */
    public static function walletFor(User $user, string $currency): self
    {
        return static::firstOrCreate(
            ['owner_id' => $user->id, 'currency' => strtoupper($currency)],
            [
                'code' => 'W-' . strtoupper($currency) . '-' . $user->id,
                'name' => $user->name . ' (' . strtoupper($currency) . ')',
                'country' => $user->country ?: User::DEFAULT_COUNTRY,
                'is_active' => true,
            ]
        );
    }

    /**
     * Which currencies a person holds wallets in is decided by the country they work from.
     * A desk in Uganda takes shillings and dollars; a desk in China pays out yuan and settles
     * in dollars. Nobody is handed a wallet in a currency they never touch, which is why a
     * Uganda agent no longer sees an empty yuan account beside their commission.
     *
     * @return array<int, string>
     */
    public static function currenciesFor(User $user): array
    {
        return ($user->country ?: User::DEFAULT_COUNTRY) === 'CN'
            ? ['RMB', 'USD']
            : ['UGX', 'USD'];
    }

    /**
     * The wallet accounts this person's country gives them, created on demand.
     *
     * @return array<string, self>
     */
    public static function walletsFor(User $user): array
    {
        $wallets = [];
        foreach (static::currenciesFor($user) as $currency) {
            $wallets[$currency] = static::walletFor($user, $currency);
        }

        return $wallets;
    }

    public function scopeCompanyAccounts(Builder $query): Builder
    {
        return $query->whereNull('owner_id');
    }

    public function scopeAgentWallets(Builder $query): Builder
    {
        return $query->whereNotNull('owner_id');
    }
}

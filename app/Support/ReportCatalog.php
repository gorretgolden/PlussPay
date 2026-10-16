<?php

namespace App\Support;

use App\Models\User;

/**
 * Which report tabs a reader may open, and what each one is called when they do.
 *
 * Access is written as abilities rather than role names because the Roles screen grants
 * abilities. A list of role names here would be a second answer to "who sees this report", and it
 * would be the one that quietly disagrees with whatever she ticked last week. To give a role a
 * report, tick the ability this table names; nothing here has to change.
 */
final class ReportCatalog
{
    /**
     * Reading the whole book is the same permission that opens every other desk's rows, so the
     * cross-desk reports hang off it rather than off a reports-only ability that could be ticked
     * while the rows themselves stay hidden.
     */
    public const LEDGER_ABILITY = 'view-all-transactions';

    /**
     * key => office label, desk label, hint, extra ability (null means view-reports is enough).
     * A null label means that reader does not open that tab at all.
     */
    public const REPORTS = [
        'hub' => [
            'office' => 'Reports Hub',
            'desk' => 'My Reports',
            'hint' => ['Transaction summaries and analytics', 'What you booked, what it earned you, and what you are still holding'],
        ],
        'transaction' => [
            'office' => 'Transaction Report',
            'desk' => 'My Transfers',
            'hint' => ['Detailed transaction breakdown', 'Every transfer you booked, with its receipt number'],
        ],
        'exchanges' => [
            'office' => null,
            'desk' => 'My Exchanges',
            'hint' => [null, 'Every buy and sell you did at the counter'],
            'ability' => 'view-exchanges',
        ],
        'charge' => [
            'office' => null,
            'desk' => 'My Charge Collected',
            'hint' => [null, 'The charge you kept, counted by day, by week and by month'],
        ],
        'wallet' => [
            'office' => null,
            'desk' => 'My Wallet Summary',
            'hint' => [null, 'What is in your hands now, what you have handed in, and what is still yours'],
            'ability' => 'view-wallets',
        ],
        'daily' => [
            'office' => 'Daily Summary',
            'desk' => 'My Daily Summary',
            'hint' => ['Day-by-day transaction summary', 'A line for each day, with the completed, pending and cancelled counts'],
        ],
        'revenue' => [
            'office' => 'Revenue Report',
            'desk' => null,
            'hint' => ['Revenue and charge analysis', null],
            'ability' => self::LEDGER_ABILITY,
        ],
        'profit' => [
            'office' => 'Profit Report',
            'desk' => null,
            'hint' => ['The charge kept plus what the rates earned on the money that travelled', null],
            'ability' => 'view-profit',
        ],
        'commission' => [
            'office' => 'Commission Report',
            'desk' => null,
            'hint' => ['What the desk earned on the transfers they booked', null],
            'ability' => self::LEDGER_ABILITY,
        ],
        'agent' => [
            'office' => 'Agent Report',
            'desk' => null,
            'hint' => ['Agent performance metrics', null],
            'ability' => self::LEDGER_ABILITY,
        ],
        'customer' => [
            'office' => 'Customer Report',
            'desk' => null,
            'hint' => ['Who the money moved for, and how much each one sent', null],
            'ability' => self::LEDGER_ABILITY,
        ],
        'rates' => [
            'office' => 'Exchange Rate Report',
            'desk' => null,
            'hint' => ['What each dollar rate carried and what it earned', null],
            'ability' => 'view-exchange-rates',
        ],
    ];

    /** Sidebar order for a reader of the whole book. */
    public const OFFICE_ORDER = ['hub', 'transaction', 'revenue', 'profit', 'commission', 'agent', 'daily', 'customer', 'rates'];

    /** Sidebar order for a reader of only their own records: the five reports that are theirs. */
    public const DESK_ORDER = ['hub', 'transaction', 'exchanges', 'charge', 'wallet', 'daily'];

    /**
     * A desk is anybody who may not read every other desk's rows. Staff sits here too, because
     * staff booked nothing and saw only their own records under the old role gates.
     */
    public static function isDesk(User $user): bool
    {
        return ! $user->can(self::LEDGER_ABILITY);
    }

    /** @return array<string, string> report key => label, in sidebar order */
    public static function nav(User $user): array
    {
        $labels = [];

        foreach (self::order($user) as $key) {
            if (self::open($user, $key)) {
                $labels[$key] = self::title($user, $key);
            }
        }

        return $labels;
    }

    /**
     * Whether this reader may open this tab. The type comes off the query string, so an unknown
     * key is refused here rather than falling through to the hub and looking like a working page.
     */
    public static function open(User $user, string $key): bool
    {
        $entry = self::REPORTS[$key] ?? null;

        if ($entry === null) {
            return false;
        }

        if (($entry['ability'] ?? null) !== null && ! $user->can($entry['ability'])) {
            return false;
        }

        return $entry[self::side($user)] !== null;
    }

    /** @return array<string, bool> report key => render its section, for the page and its charts */
    public static function shown(User $user): array
    {
        return array_fill_keys(array_keys(self::nav($user)), true);
    }

    public static function title(User $user, string $key): string
    {
        return self::REPORTS[$key][self::side($user)] ?? self::REPORTS['hub'][self::side($user)];
    }

    public static function hint(User $user, string $key): string
    {
        $index = self::side($user) === 'office' ? 0 : 1;

        return self::REPORTS[$key]['hint'][$index] ?? self::REPORTS['hub']['hint'][$index];
    }

    private static function order(User $user): array
    {
        return self::isDesk($user) ? self::DESK_ORDER : self::OFFICE_ORDER;
    }

    private static function side(User $user): string
    {
        return self::isDesk($user) ? 'desk' : 'office';
    }
}

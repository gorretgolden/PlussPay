@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    $me = auth()->user();
    $hour = (int) now()->format('G');
    // Kampala hours: the small band is the night, and a 3 AM reader is not being wished
    // a morning. The hour comes from the app time zone, so only these thresholds matter.
    $greeting = match (true) {
        $hour >= 5 && $hour < 12 => 'Good morning',
        $hour >= 12 && $hour < 18 => 'Good afternoon',
        $hour >= 18 => 'Good evening',
        default => 'Hello',
    };
    // The desk's name itself is not printed here any more, because the sidebar footer under it
    // already reads "Agent · Uganda". The one exception is a desk whose country was never filled
    // in: the balances below follow Uganda by default, so the board has to say the country is
    // unset rather than leave a reader guessing which till they are looking at.
    $desk = $me->countryLabel();

    // One shape for every figure on the board, so a zero never appears in three different words.
    // Shillings are handed over in notes, so a whole shilling figure prints without decimals and a
    // nil one prints "UGX 0". Yuan and dollars are cut by a rate, so they keep the cents they are
    // counted in and a nil one prints "¥0.00" or "$0.00" the same way. The old board mixed "nothing",
    // "UGX 0", "UGX 0.0" and "¥0.0" across four cards that all meant zero, and a reader who has to
    // work out which of them is a real figure stops trusting the ones that are not.
    $money = function ($ugx, $usd, int $dec = 0) use ($usdToUgx): string {
        if ($usdToUgx) {
            return (float) $ugx === 0.0 ? 'UGX 0' : 'UGX ' . number_format((float) $ugx, $dec);
        }

        return (float) $usd === 0.0 ? '$0.00' : '$' . number_format((float) $usd, 2);
    };
    // A computed figure still carries the one decimal that fractions of a booking land in, but a
    // computed figure that comes out even drops the ".0" rather than standing out from every other
    // even shilling on the page. A loss keeps the word so a minus sign is never the only clue.
    $shilling = function ($value): string {
        $figure = number_format(abs((float) $value), 1);
        if (str_ends_with($figure, '.0')) {
            $figure = substr($figure, 0, -2);
        }

        return ((float) $value < 0 ? 'Loss ' : '') . 'UGX ' . $figure;
    };
    $shillingTone = fn ($value) => $value < 0 ? 'text-red-600' : 'text-gray-900';
    // "3 receivers", "1 receiver": the count and the word it counts, so no card on the board has to
    // spell its own plural out and no reader meets "1 agents".
    $plural = function (int $count, string $one, ?string $many = null): string {
        return number_format($count) . ' ' . ($count === 1 ? $one : ($many ?? $one . 's'));
    };
    // The money a pile with nothing in it would have been paid in, taken from where this desk stands
    // so a China board prints ¥0.00 rather than a shilling zero it never deals in.
    $nilPayout = (($me->country ?: \App\Models\User::DEFAULT_COUNTRY) === 'CN') ? 'RMB' : 'UGX';

    // One card set per window, split into the three questions the board is asked, in the order they
    // get asked: what was booked, how far the money has travelled, what it came to. Fourteen cards
    // stacked one per row made the figures a desk reads every day sit three screens below the fold;
    // three labelled rows of four put the whole book above it.
    $panels = [];
    foreach (['today' => 'Today', 'month' => 'This Month', 'all' => 'All Time'] as $key => $label) {
        $t = $windows[$key];
        $china = $deskPayouts[$key]['china'];
        $uganda = $deskPayouts[$key]['uganda'];
        $spent = $spending[$key];

        $transfers = [
            ['Transfers completed', number_format($t['completed']), 'text-gray-900'],
            ['Amount sent', $money($t['sent_ugx'], $t['sent_usd']), 'text-gray-900'],
            // The whole pile on the bookings that are not finished yet, charge and all because they
            // cross together, with the age of the newest one. It used to be called "To Be Received"
            // under the line "money to be sent", which named the opposite direction from the figure
            // beside it; what is actually waiting is the settling.
            ['Money still to settle', $money($t['pending_ugx'], $t['pending_usd']), 'text-gray-900',
                $t['pending'] === 0 ? 'nothing open' : $plural((int) $t['pending'], 'booking') . ' open',
                $t['latest_at']],
            ['Charge collected', $money($t['charge_ugx'], $t['charge_usd']), 'text-gray-900',
                'what the desk charged for the transfers above'],
        ];
        // The desks the agents answer to get one extra figure: the slice of the window above that the
        // agents' own hands collected. Everyone else never sees the card at all. The title names the
        // country those agents stand in, so the two desks' cash stays apart.
        if (isset($agentTaken[$key])) {
            $takenBy = 'Taken By Agents';
            if (count($agentTakenCountries) === 1) {
                $takenBy .= ' In '.$agentTakenCountries[0];
            } elseif (count($agentTakenCountries) > 1) {
                $takenBy .= ' In '.implode(' And ', $agentTakenCountries);
            }
            $transfers[] = [$takenBy, $money($agentTaken[$key], 0, 0), 'text-gray-900', 'principal plus charge at the counter'];
        }

        // Two figures per direction, which is all the corridor can honestly answer: what the far desk
        // still owes a receiver, and what it has already handed over. Four cards used to cover the
        // same ground (Yuan To Receive, Yuan In China Unpaid, Yuan Paid, Withdrawn In China) and every
        // one of them was a yuan pile in a different pair of hands, so the reader had to add three of
        // them to find the money actually moving. The send is folded into the note instead: an unpaid
        // booking whose dollars have not crossed is the same debt, and the desk needs to know how many
        // of them are waiting on that rather than on a hand-over.
        $payouts = [
            ['To pay in China', \App\Models\Transaction::formatPayouts($china['owed'], 'RMB'), 'text-gray-900',
                $china['owed_count'] === 0 ? 'every receiver there is paid'
                    : $plural((int) $china['owed_count'], 'receiver') . ' owed'
                        . ($china['owed_stranded'] > 0 ? ' · ' . $plural((int) $china['owed_stranded'], 'pile') . ' not sent yet' : '')],
            ['Paid in China', \App\Models\Transaction::formatPayouts($china['paid'], 'RMB'), 'text-gray-900',
                $china['paid_count'] === 0 ? 'nothing handed over there yet' : 'handed to ' . $plural((int) $china['paid_count'], 'receiver')],
            ['To pay in Uganda', \App\Models\Transaction::formatPayouts($uganda['owed'], 'UGX'), 'text-gray-900',
                $uganda['owed_count'] === 0 ? 'every receiver there is paid' : $plural((int) $uganda['owed_count'], 'receiver') . ' owed'],
            ['Paid in Uganda', \App\Models\Transaction::formatPayouts($uganda['paid'], 'UGX'), 'text-gray-900',
                $uganda['paid_count'] === 0 ? 'nothing handed over there yet' : 'handed to ' . $plural((int) $uganda['paid_count'], 'receiver')],
        ];

        $earnings = [];
        if (isset($profit[$key])) {
            $earnings[] = ['FX profit', $shilling($profit[$key]['exchange']), $shillingTone($profit[$key]['exchange'])];
            $earnings[] = ['Overall profit', $shilling($profit[$key]['total']), $shillingTone($profit[$key]['total'])];
        }
        if ($me->can('view-expenses')) {
            // Money going out beside the money coming in, in the same window the switch above is set
            // to. It was a card of its own under the Overview, and on a day with no spending that
            // card was a heading over one empty sentence.
            // Whoever reads the whole book is shown which desk each pile was spent at, because yuan
            // and shillings on one line otherwise read as one figure that has been broken in two. A
            // desk that only ever spends its own money does not need its own country named back to it.
            $namesDesks = $me->can('view-all-expenses') && count($spent['desks']) > 1;
            $spentFigures = collect($spent['desks'])
                ->map(fn (array $deskRow) => collect($deskRow['totals'])
                    ->map(fn ($amount, string $currency) => \App\Models\Account::format($currency, $amount))
                    ->implode(' and ')
                    .($namesDesks ? ' at '.$deskRow['label'] : ''))
                ->implode(' · ');
            $spentNames = collect($spent['desks'])
                ->flatMap(fn (array $deskRow) => $deskRow['people'])
                ->unique()->sort()->take(2)->values();

            $earnings[] = ['Spent', $spentFigures !== '' ? $spentFigures : \App\Models\Account::format($nilPayout, 0), 'text-gray-900',
                $spent['count'] === 0 ? 'nothing recorded'
                    : $plural((int) $spent['count'], 'entry', 'entries')
                        . ($spentNames->isNotEmpty() ? ' · '.$spentNames->implode(', ').($spent['count'] > $spentNames->count() ? ' and more' : '') : '')];
        }

        $panels[$key] = [
            'label' => $label,
            'rows' => array_values(array_filter([
                ['label' => 'Transfers', 'cards' => $transfers],
                ['label' => 'China and Uganda payouts', 'cards' => $payouts],
                ['label' => 'Money and profit', 'cards' => $earnings],
            ], fn (array $row) => $row['cards'] !== [])),
            'pending' => $t['pending'],
            'cancelled' => $t['cancelled'],
            'avg_ugx' => $t['avg_ugx_sent'],
            'avg_usd' => $t['avg_usd_sent'],
            'unpriced' => $profit[$key]['unpriced'] ?? 0,
        ];
    }

    $hasTransfers = array_sum($chartData['transactions']) > 0;
    $hasCharge = array_sum($chartData['revenue_ugx']) > 0;
    $hasExchange = isset($chartData['exchange_ugx']) && array_sum($chartData['exchange_ugx']) > 0;

    // The whole name is greeted, never just its first word, and an account named after nothing but
    // its own role is greeted by that role's display name. That work is done in the controller, as
    // $greetAs, because it has to read the role the same way the badge below does.

    // Kampala is three hours ahead of UTC all year, so the clock is stamped with the zone the desk
    // works in rather than whatever the laptop happens to be set to. The script that keeps it
    // ticking uses the same figure.
    $clockDate = now()->format('D, j M Y');
    $clockTime = now()->format('g:i A');
@endphp

<style>
    /* The tab look follows aria-selected alone, so the styling cannot drift from the state. */
    [role="tab"][aria-selected="true"] { background: #fff; color: #111827; box-shadow: 0 1px 2px 0 rgb(0 0 0 / .05); }
    [role="tab"][aria-selected="false"] { color: #4b5563; }
    [role="tab"][aria-selected="false"]:hover { color: #111827; }
    [role="tab"]:focus-visible { outline: 2px solid var(--brand-300, #86efac); outline-offset: 1px; }

    /* A figure takes the headline size whenever its card can hold it on one line and gives up
       only the pixels it needs. Digits and commas in tabular figures measure about half a font
       size each, so dividing the card by the length of the string is the largest size it fits.
       Four cards across a laptop card is roughly a third of the width the old single-column row
       gave each one, so this is what keeps a long loss on the headline line. A browser without
       container queries keeps the 1.5rem above and behaves as it did before. */
    .stat-card { container-type: inline-size; }
    .stat-value { font-size: 1.5rem; font-size: clamp(.875rem, calc(100cqi / (var(--len) * .52)), 1.5rem); }

    /* The desk box wears a light grey fill so it reads as a field inside the white card. The
       layout's dark theme paints every input with its own darker surface, which would sink a well
       into that fill, so the box keeps a transparent field and the wrapper carries the colour. */
    .dark [data-dashboard-search] input { background-color: transparent; }
</style>

{{-- The board opens with one card that holds everything a desk reaches for before it reads a
     figure: the greeting, the role, the hour, the box that searches the desk and the controls.
     The search used to sit in a card of its own underneath, which spent a band of vertical space
     on one line; it now shares this card, and on a narrow board it drops to a row of its own at
     full width instead of squeezing the greeting. On a wide board the three blocks are one row held
     on the middle line, with the search taking whatever width the greeting leaves and giving back
     anything past 512px. The page title is kept for a screen reader and the sidebar already says
     which screen this is, so the big word "Dashboard" is gone. The desk's country and the account
     name are not repeated either, because the sidebar footer under them carries both, word for
     word. --}}
<div class="mb-4 rounded-2xl border border-gray-200 bg-white shadow-sm p-4 sm:p-5 xl:p-6">
    <div class="flex flex-wrap items-center gap-2.5 sm:gap-3 xl:gap-x-4">
        <h1 class="sr-only">Dashboard</h1>
        {{-- The mark follows the same hour bands as the words: a sun for the desk hours, a moon
             for the night shift. It carries no text, so it cannot repeat anything on the page.
             This block is the one that gives way when the board is narrow: the greeting truncates
             rather than pushing the controls off the screen, and the meta line under it is free to
             wrap onto as many short lines as it needs. --}}
        <div class="flex min-w-0 flex-1 items-center gap-2.5 sm:gap-3">
            <span aria-hidden="true" class="w-9 h-9 sm:w-10 sm:h-10 shrink-0 rounded-full flex items-center justify-center {{ $hour >= 6 && $hour < 18 ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700' }}">
                @if($hour >= 6 && $hour < 18)
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4V2m0 20v-2m8-8h2M2 12h2m13.66-5.66l1.41-1.41M4.93 19.07l1.41-1.41m0-11.32L4.93 4.93m14.14 14.14l-1.41-1.41M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                @else
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.5 13.5A8.5 8.5 0 1110.5 3.5a7 7 0 0010 10z"/></svg>
                @endif
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-base xl:text-lg font-semibold leading-snug text-gray-900">{{ $greeting }}, {{ $greetAs }}.</p>
                {{-- The badge is kept even though the sidebar footer names the role too: it is the
                     board's statement of what this account may do, and it sits beside the balances
                     it governs. No seconds on the clock: the figure only has to be right to the
                     minute, and a digit running every second pulls the eye away from the balances.
                     The zone is printed because every figure on this board is kept in Kampala
                     time, whatever the machine reading it is set to. --}}
                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] leading-tight text-gray-600">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800 capitalize ring-1 ring-inset ring-green-200">{{ $role }}</span>
                    <span class="tabular-nums" id="liveClock" data-zone-offset="180">{{ $clockDate }} · {{ $clockTime }} EAT</span>
                    {{-- Set once the board has been pulled again. Until then the line is empty rather than
                         guessing at a time nobody asked for. --}}
                    <span class="tabular-nums text-gray-400" id="lastRefreshed"></span>
                </div>
            </div>
        </div>
        {{-- The three controls a shift actually ends with live beside the clock that says how old the
             readings are, because on a phone the sidebar is a closed drawer and a control that exists
             only there is a control nobody finds mid-shift. Below the tablet breakpoint each is a 40px
             glyph with its word dropped, and the accessible name carries the state instead. Reload is
             the solid one, the bell and the sign-out are the ghosts that colour up once aimed at. --}}
        <div class="flex shrink-0 items-center gap-2 no-print xl:order-3">
            @if($bellGate)
            <a href="{{ route('notifications.index') }}" title="What happened"
                aria-label="{{ $bellCount > 0 ? 'What happened, '.number_format($bellCount).' unread' : 'What happened' }}"
                class="relative inline-flex w-10 h-10 md:w-auto md:px-3 shrink-0 items-center justify-center gap-1.5 rounded-xl border border-gray-200 bg-white text-[13px] font-semibold text-gray-700 transition duration-150 hover:bg-gray-50 active:translate-y-px focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300 focus-visible:ring-offset-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <span class="hidden md:inline">What happened</span>
                {{-- The count hangs outside the box so it never pushes the word along the row, and a
                     queue too long for the pill is still one honest figure. --}}
                @if($bellCount > 0)
                <span class="absolute -top-1 -right-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold leading-none tabular-nums text-white">{{ $bellCount > 99 ? '99+' : number_format($bellCount) }}</span>
                @endif
            </a>
            @endif
            <button type="button" onclick="reloadDashboard(this)" aria-label="Reload this board" title="Reload"
                class="inline-flex w-10 h-10 md:w-auto md:px-3 shrink-0 items-center justify-center gap-1.5 rounded-xl bg-green-800 text-[13px] font-semibold text-white transition duration-150 hover:bg-green-700 active:translate-y-px focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300 focus-visible:ring-offset-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                <span class="hidden md:inline">Reload</span>
            </button>
            <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                @csrf
                <button type="submit" aria-label="Log out" title="Log out"
                    class="inline-flex w-10 h-10 md:w-auto md:px-3 shrink-0 items-center justify-center gap-1.5 rounded-xl border border-gray-200 bg-white text-[13px] font-semibold text-gray-700 transition duration-150 hover:bg-gray-50 active:translate-y-px focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300 focus-visible:ring-offset-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013-3v1"/></svg>
                    <span class="hidden md:inline">Log out</span>
                </button>
            </form>
        </div>
        {{-- One box that searches the whole desk: the pages this account can open, under the same
             names the sidebar gives them. The list is read off the sidebar links already on the
             page, so a page only ever appears here if the sidebar itself shows it, and no
             permission check has to live in two places. On a wide board it takes the middle of the
             row between the greeting and the controls and grows into whatever space is left there;
             anywhere narrower it is a row of its own. The "/" hint is dropped below the tablet
             breakpoint, where the box is full width and no keyboard is in reach. --}}
        <div class="relative w-full min-w-0 xl:order-2 xl:max-w-[32rem] xl:flex-1" data-dashboard-search>
            <div class="flex h-11 items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 transition duration-150 focus-within:ring-2 focus-within:ring-green-600">
                <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35m1.35-5.65a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="search" id="dashboardSearch" placeholder="Search the desk"
                    class="w-full min-w-0 bg-transparent outline-none text-sm text-gray-900 placeholder:text-gray-400"
                    autocomplete="off" spellcheck="false"
                    role="combobox" aria-expanded="false" aria-controls="dashboardSearchResults"
                    aria-autocomplete="list" aria-label="Search the desk's pages">
                <kbd class="hidden md:inline-block text-[11px] text-gray-400 border border-gray-200 rounded px-1.5 py-0.5 leading-5">/</kbd>
            </div>
            <div id="dashboardSearchResults" class="hidden absolute left-0 right-0 top-full mt-1.5 z-40 rounded-xl border border-gray-200 bg-white shadow-xl overflow-hidden">
                <ul id="dashboardSearchList" role="listbox" aria-label="Matching pages" class="max-h-72 overflow-y-auto py-1"></ul>
                <p id="dashboardSearchEmpty" class="hidden px-4 py-3 text-sm text-gray-500">Nothing on the desk matches that.</p>
            </div>
        </div>
    </div>
    @unless($desk)
    {{-- Only the missing case is printed. When a country is set the sidebar footer already names it,
         and a second copy beside the greeting is the duplication she asked to take out. --}}
    <p class="text-[13px] text-gray-600 mt-2">{{ $desk ?: 'Desk country not set' }}. The balances below follow Uganda by default, so set the country on your account to see the right till.</p>
    @endunless
</div>

{{-- The till comes first. It used to sit under the shortcuts and the prices, three screens down,
     even though it is the only part of this page anybody reads every day. The switch decides which
     stretch every figure below answers for, so it is stamped on the card that holds them rather than
     repeated per row. --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-5 mb-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h2 class="text-lg font-semibold text-gray-900">Overview</h2>
        <div role="tablist" aria-label="Time window" class="inline-flex gap-1 rounded-lg border border-gray-200 bg-gray-50 p-1">
            @foreach($panels as $key => $panel)
            <button type="button" role="tab" id="tab-{{ $key }}" data-window="{{ $key }}" aria-controls="panel-{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}" class="rounded-md px-3 py-1.5 text-sm font-medium">{{ $panel['label'] }}</button>
            @endforeach
        </div>
    </div>

    @foreach($panels as $key => $panel)
    <div role="tabpanel" id="panel-{{ $key }}" aria-labelledby="tab-{{ $key }}" @if(! $loop->first) hidden @endif>
        @foreach($panel['rows'] as $row)
        <div class="{{ $loop->first ? '' : 'mt-5' }}">
            <h3 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ $row['label'] }}</h3>
            {{-- Four across on a laptop, two on a phone. The old row sized itself by a min-width and
                 gave way to one card per line at exactly the width the desk's screen is. --}}
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach($row['cards'] as $card)
                @php [$cardLabel, $cardValue, $cardTone, $cardNote, $cardStamp] = array_pad($card, 5, null); @endphp
                <div class="stat-card min-w-0 rounded-lg border border-gray-200 p-3">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $cardLabel }}</div>
                    <div class="stat-value mt-1.5 font-bold {{ $cardTone }} tabular-nums whitespace-nowrap" style="--len: {{ strlen($cardValue) }}">{{ $cardValue }}</div>
                    @if($cardNote !== null)
                    <div class="mt-1 truncate text-xs text-gray-500" title="{{ $cardNote }}">{{ $cardNote }}</div>
                    @endif
                    @if($cardStamp !== null)
                    <x-wallet-updated :at="$cardStamp" class="mt-1 text-xs" />
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @endforeach
        <p class="text-xs text-gray-500 mt-3">{{ number_format($panel['cancelled']) }} cancelled · average transfer {{ $money($panel['avg_ugx'], $panel['avg_usd']) }}</p>
        @if($panel['unpriced'] > 0)
        <p class="text-xs text-amber-700 mt-2">{{ number_format($panel['unpriced']) }} without rates</p>
        @endif
    </div>
    @endforeach
</div>

@php
    // Shortcuts to the work people come here to do, kept to the five bookings and payouts a desk
    // actually reaches for from this screen. Expenses, Exchange Rates, Transactions and Add Customer
    // were tiles here as well as sidebar links, and nine tiles two lines tall pushed the figures off
    // the page. A page that already opened with them no longer needs a second copy of the menu.
    // Each tile is dropped when its ability is missing, so nobody is handed a button that bounces
    // back with a permission error, and a role that books nothing at all gets no card rather than a
    // row of dead links.
    //
    // A hand only ever sends one way: the cash is handed over where the desk is, so an agent in
    // Uganda sends to China and an agent in China sends to Uganda. Whoever sees the whole book
    // keys in for either desk, which the booking form already lets them do, so the lead agent and
    // the office are offered both roads rather than having to turn the form around by hand.
    $sendToChina = ['create-transaction', 'Send to China', route('transactions.create', ['destination' => \App\Models\Transaction::TO_CHINA]), 'M4 13a2 2 0 012-2h4.5a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4zM8 14.1a1.2 1.2 0 100 2.6 1.2 1.2 0 000-2.6zM13.5 12.5L20 5.5M15.5 5H20v4.5', null, true];
    $sendToUganda = ['create-transaction', 'Send to Uganda', route('transactions.create', ['destination' => \App\Models\Transaction::TO_UGANDA]), 'M11 13a2 2 0 012-2h4.5a2 2 0 012 2v4a2 2 0 01-2 2h-4.5a2 2 0 01-2-2v-4zM15 14.1a1.2 1.2 0 100 2.6 1.2 1.2 0 000-2.6zM10.5 12.5L4 5.5M8.5 5H4v4.5', null, true];
    $sendActions = $me->can('view-all-transactions')
        ? [$sendToChina, $sendToUganda]
        : [\App\Models\Transaction::destinationForDesk($me->country) === \App\Models\Transaction::TO_UGANDA ? $sendToUganda : $sendToChina];

    // Money is withdrawn where the receiver collects it: receivers in China collect at a desk in
    // China, and receivers in Uganda collect at a desk in Uganda. A hand at one desk is offered the
    // queue at its own counter, while whoever sees the whole book is offered both, exactly as the
    // send tiles split. The payout pen clears either queue wherever its own desk stands, so the same
    // ability opens both pages. The badge is the one thing worth the pixels: it is the number of
    // people standing at that counter, and it is why the tile was pressed.
    $canWithdraw = $me->can('view-transactions') && $me->can('confirm-china-payout');

    $withdrawGlyph = 'M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v10m0 0l-4-4m4 4l4-4';
    $withdrawChina = ['confirm-china-payout', 'Pay out in China', route('china.index').'#payouts', $withdrawGlyph,
        $withdrawalsWaiting > 0 ? number_format($withdrawalsWaiting) : null, false,
        $withdrawalsWaiting === 1 ? '1 receiver waiting to be paid' : $withdrawalsWaiting.' receivers waiting to be paid'];
    $withdrawUganda = ['confirm-china-payout', 'Pay out in Uganda', route('uganda.index').'#payouts', $withdrawGlyph,
        $ugandaWithdrawalsWaiting > 0 ? number_format($ugandaWithdrawalsWaiting) : null, false,
        $ugandaWithdrawalsWaiting === 1 ? '1 receiver waiting to be paid' : $ugandaWithdrawalsWaiting.' receivers waiting to be paid'];

    $withdrawActions = $canWithdraw
        ? ($me->can('view-all-transactions')
            ? [$withdrawChina, $withdrawUganda]
            : [($me->country ?: \App\Models\User::DEFAULT_COUNTRY) === 'CN' ? $withdrawChina : $withdrawUganda])
        : [];

    // Approving is one job with one home: the requests page. The chip stays on a lead agent's board
    // whether or not anything is waiting, because it is what they are there for and an empty queue
    // is information; the count only appears when there is a queue to clear.
    $approveAction = $me->hasRole('super-agent') ? [
        'approve-cash-handover',
        'Approve hand-ins',
        route('cash-handovers.index'),
        'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        $handoverQueue->isNotEmpty() ? number_format($handoverQueue->count()) : null,
        false,
        $handoverQueue->isEmpty()
            ? 'Nothing is waiting for you yet'
            : ($handoverQueue->count() === 1 ? '1 request waiting for you' : $handoverQueue->count().' requests waiting for you'),
    ] : null;

    $quickActions = array_values(array_filter([
        ...$sendActions,
        ...$withdrawActions,
        ...($approveAction ? [$approveAction] : []),
        ['create-exchange', 'Record Exchange', route('exchanges.create'), 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4', null, false, 'Book money swapped at the counter'],
    ], fn (array $a) => $me->can($a[0])));
@endphp

@if($quickActions !== [])
<div class="rounded-xl border border-gray-200 bg-white shadow-sm p-3 sm:p-4 mb-6">
    <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">Quick Actions</h2>
    {{-- One line of chips that wraps rather than a grid of tall tiles: the labels are three words at
         most, so nothing needs the height, and the row is what keeps this card above the figures
         instead of in front of them. --}}
    <div class="flex flex-wrap gap-2">
        @foreach($quickActions as $action)
        @php [$ability, $label, $url, $glyph, $badge, $primary, $hint] = array_pad($action, 7, null); @endphp
        <a href="{{ $url }}" @if($hint !== null) title="{{ $hint }}" @endif class="inline-flex min-w-0 items-center gap-2 rounded-lg border px-3 py-2 text-[13px] font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300 focus-visible:ring-offset-1 {{ $primary ? 'border-green-200 bg-green-50 text-green-900 hover:border-green-300 hover:bg-green-100' : 'border-gray-200 bg-white text-gray-900 hover:border-green-300 hover:bg-green-50' }}">
            <span aria-hidden="true" class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full {{ $primary ? 'bg-green-200 text-green-900' : 'bg-green-100 text-green-800' }}">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $glyph }}"/></svg>
            </span>
            {{ $label }}
            @if($badge !== null)
            <span class="ml-0.5 rounded-full bg-red-100 px-1.5 py-0.5 text-[11px] font-bold tabular-nums text-red-700 ring-1 ring-inset ring-red-200">{{ $badge }}</span>
            @endif
        </a>
        @endforeach
    </div>
</div>
@endif

{{-- The till sits under the figures and the shortcuts: the desk reads its day, presses the thing it
     needs, and only then looks at the wallets it has to work with. Account::currenciesFor() orders
     the wallets by the desk's country, so the local currency is always the first card. The controller
     hands an empty list to anyone who reads every wallet instead of holding one, and the row is
     left out for them.

     A card is one figure, its stamp and its links. The splits that used to stand under the balance
     (awaiting approval, fees, handed in) live on the wallet page and the reports, so the board
     stays clean. --}}
@if($wallets !== [] || $handoverInsight)
{{-- An agent's hand-in requests span the right section beside their account cards, so the
     cash they hold and the cash on its way up read as one picture. Every other wallet
     holder keeps the plain two-across grid. --}}
<div class="mb-6 {{ $handoverInsight ? 'grid grid-cols-1 lg:grid-cols-2 gap-4 items-start' : '' }}">
<div class="{{ $handoverInsight ? 'grid grid-cols-1 gap-4' : 'grid grid-cols-1 sm:grid-cols-2 gap-4' }}">
    @foreach($wallets as $currency => $account)
    @php
        $balance = (string) $account->balance();
        $figure = fn (string|int|float $value) => \App\Models\Account::format($currency, $value);
    @endphp
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $currency }} Wallet</div>
                <a href="{{ route('wallet.index') }}" class="block text-2xl font-bold text-gray-900 mt-2 tabular-nums whitespace-nowrap hover:underline">{{ $figure($balance) }}</a>
                <x-wallet-updated :at="$account->lastMovementAt()" class="mt-1" />
                {{-- Approved hand-ins left the drawer, so the money the agent is still answerable
                     for reads them back in. The line stays off the card until there is a pile to
                     add, so the balance is never printed twice. --}}
                @if($handoverInsight && bccomp((string) ($handoverInsight['approved'][$currency] ?? '0.00'), '0', 2) > 0)
                @php $heldTotal = bcadd((string) $account->balance(), (string) $handoverInsight['approved'][$currency], 2); @endphp
                <p class="text-[13px] mt-2">
                    <span class="font-semibold text-gray-900">Total money held {{ $figure($heldTotal) }}</span>
                    <span class="text-gray-500">· includes {{ $figure($handoverInsight['approved'][$currency]) }} handed in</span>
                </p>
                @endif
            </div>
            {{-- Decorative only. The line above already names the wallet, so the circle carries a
                 glyph rather than a second spelling of the same currency. --}}
            <span aria-hidden="true" class="w-10 h-10 shrink-0 rounded-full bg-green-100 text-green-800 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            </span>
        </div>

        @if(bccomp($balance, '0', 2) <= 0)
        <p class="text-[13px] text-gray-700 mt-2">No balance yet. @can('adjust-wallets')<a href="{{ route('accounts.entries.create') }}" class="font-semibold text-gray-800 underline underline-offset-2">Add money</a>@endcan</p>
        @endif

        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-gray-100 pt-3">
            @if($me->hasRole('agent'))
            <a href="{{ route('cash-handovers.index') }}" class="text-[13px] font-semibold text-green-800 hover:underline">History</a>
            @endif
            <a href="{{ route('wallet.index') }}" class="ml-auto text-[13px] font-semibold text-green-800 hover:underline">Open the wallet</a>
        </div>
    </div>
    @endforeach
</div>

{{-- An insight, not a desk: how much of this hand's cash has gone up to the lead agent, in whose
     hands it is sitting now, and how long ago the last one was sent. Every figure is this person's
     own, read from their account in the controller rather than from anything the address bar
     carries. --}}
@if($handoverInsight)
@php
    $insightLabels = [
        \App\Models\CashHandover::PENDING => 'Waiting on approval',
        \App\Models\CashHandover::APPROVED => 'Approved',
        \App\Models\CashHandover::DECLINED => 'Sent back',
    ];
    $nilCurrency = array_key_first($wallets) ?? 'UGX';
    $lastSent = $handoverInsight['latest'];
@endphp
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-5 h-fit">
    <div class="flex flex-wrap items-center gap-2">
        <h2 class="text-base font-semibold text-gray-900 mr-auto">Cash You Hand In</h2>
        <a href="{{ route('cash-handovers.index') }}" class="text-sm font-semibold text-green-800 hover:underline">Every hand-in</a>
    </div>
    <ul class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
        @foreach($handoverInsight['statuses'] as $status => $group)
        @php
            $totals = collect($group['totals'])
                ->filter(fn ($value) => bccomp((string) $value, '0', 2) > 0)
                ->map(fn ($value, string $each) => \App\Models\Account::format($each, $value))
                ->values()
                ->all();
        @endphp
        <li class="rounded-lg border border-gray-200 p-3">
            <div class="text-[13px] font-semibold uppercase tracking-wide text-gray-500">{{ $insightLabels[$status] ?? ucfirst($status) }}</div>
            <div class="text-xl font-bold text-gray-900 mt-1 tabular-nums whitespace-nowrap">{{ number_format($group['count']) }} {{ $group['count'] === 1 ? 'request' : 'requests' }}</div>
            <div class="text-[13px] text-gray-700 mt-0.5 tabular-nums">{{ $totals === [] ? \App\Models\Account::format($nilCurrency, 0) : implode(' and ', $totals) }}</div>
        </li>
        @endforeach
    </ul>
    @if($lastSent)
    <p class="text-[13px] text-gray-700 mt-3">
        The last one was {{ $lastSent->figure() }}, {{ strtolower($insightLabels[$lastSent->status] ?? $lastSent->status) }} ({{ $lastSent->reference }}).
    </p>
    <x-wallet-updated :at="$lastSent->created_at" label="Sent" class="mt-0.5" />
    @else
    <p class="text-[13px] text-gray-700 mt-3">Nothing handed in yet. Cash you send stays in your balance until the lead agent approves it.</p>
    @endif
</div>
@endif
</div>
@endif

{{-- The lead's own earnings: the charges on the bookings they took plus the charges the agents
     handed in with their cash, this month, in shillings. The wallet page keeps the history; the
     card names the month's figure on the screen the desk opens first. --}}
@if($chargesCollected !== null)
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-5 mb-6">
    <div class="flex flex-wrap items-center gap-2">
        <h2 class="text-base font-semibold text-gray-900 mr-auto">Charges Collected</h2>
        <a href="{{ route('wallet.index') }}" class="text-sm font-semibold text-green-800 hover:underline">Open the wallet</a>
    </div>
    <div class="text-2xl font-bold text-gray-900 mt-2 tabular-nums">{{ \App\Models\Account::format('UGX', $chargesCollected) }}</div>
    <p class="text-[13px] text-gray-700 mt-1">{{ $feesPeriod }}: your bookings' charges and what the agents handed in.</p>
</div>
@endif

@can('view-users')
@if(($logAlerts['failed_logins_24h'] ?? 0) > 0 || ($logAlerts['unresolved_errors'] ?? 0) > 0)
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
    @if(($logAlerts['failed_logins_24h'] ?? 0) > 0)
    <a href="{{ route('admin.audit-logs', ['tab' => 'login', 'status' => 'failed']) }}" class="bg-amber-50 border border-amber-200 rounded-xl shadow-sm p-5 hover:shadow transition-shadow block">
        <div class="text-xs font-semibold uppercase tracking-wide text-amber-800">Failed sign-ins (24h)</div>
        <div class="text-2xl font-bold text-amber-900 mt-2 tabular-nums">{{ number_format($logAlerts['failed_logins_24h']) }}</div>
        <div class="text-xs text-amber-800 mt-1">Check the Audit Logs for a brute-force pattern.</div>
    </a>
    @endif
    @can('view-system-logs')
    @if(($logAlerts['unresolved_errors'] ?? 0) > 0)
    <a href="{{ route('admin.system-logs', ['resolved' => '0']) }}" class="bg-red-50 border border-red-200 rounded-xl shadow-sm p-5 hover:shadow transition-shadow block">
        <div class="text-xs font-semibold uppercase tracking-wide text-red-800">Unresolved system errors</div>
        <div class="text-2xl font-bold text-red-900 mt-2 tabular-nums">{{ number_format($logAlerts['unresolved_errors']) }}</div>
        <div class="text-xs text-red-800 mt-1">Open System Errors to read and mark them resolved.</div>
    </a>
    @endif
    @endcan
</div>
@endif
@endcan

{{-- The week's shape, the price it was booked at, and the five most recent bookings, all on the
     bands a reader scans after the numbers rather than the bands that hide them. The two charts sat
     under the Overview and the prices sat above it, each in a box as tall as a screen. --}}
<div class="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3 mb-6">
    <div class="min-w-0 rounded-xl border border-gray-200 bg-white shadow-sm p-4 sm:p-5">
        <h2 class="text-sm font-semibold text-gray-900">Completed Transfers (Last 7 Days)</h2>
        @if($hasTransfers)
        <div class="relative mt-3 h-[200px] max-w-full">
            <canvas id="transactionsChart"></canvas>
        </div>
        @else
        {{-- One line, not a bordered box held open at chart height: an empty 250px well reads as a
             fault in the page rather than a quiet week. --}}
        <p class="mt-1 text-[13px] text-gray-500">Nothing completed in the last 7 days.</p>
        @endif
    </div>
    <div class="min-w-0 rounded-xl border border-gray-200 bg-white shadow-sm p-4 sm:p-5">
        @can('view-profit')
        <h2 class="text-sm font-semibold text-gray-900">Profit (Last 7 Days)</h2>
        @if($hasCharge || $hasExchange)
        <div class="relative mt-3 h-[200px] max-w-full">
            <canvas id="profitChart"></canvas>
        </div>
        @else
        <p class="mt-1 text-[13px] text-gray-500">No charge or FX profit in the last 7 days.</p>
        @endif
        @else
        <h2 class="text-sm font-semibold text-gray-900">Charge Collected (Last 7 Days)</h2>
        @if($hasCharge)
        <div class="relative mt-3 h-[200px] max-w-full">
            <canvas id="revenueChart"></canvas>
        </div>
        @else
        <p class="mt-1 text-[13px] text-gray-500">No charge collected in the last 7 days.</p>
        @endif
        @endcan
    </div>
    <div class="min-w-0 xl:col-span-2 lg:col-span-2 xl:col-span-1">
        <x-live-rate-card />
    </div>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
    <div class="px-4 sm:px-5 py-3.5 border-b border-gray-200 flex flex-wrap justify-between items-center gap-3">
        {{-- The button that used to sit here booked the same thing as Send to China in Quick
             Actions above, under a second name. One call per action per page. --}}
        <h2 class="text-base font-semibold text-gray-900">Recent Transactions</h2>
        @can('view-transactions')
        <a href="{{ route('transactions.index') }}" class="text-sm font-semibold text-green-800 hover:underline">View all</a>
        @endcan
    </div>
    {{-- Five columns that fit the page instead of eight that scroll off it. The sender, the yuan leg
         and the two receipt buttons all live on the booking itself, so the row links there instead
         of carrying three ways to reach the same page. --}}
    <div class="overflow-x-auto">
        <table class="w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Receipt</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Receiver</th>
                    <th class="px-4 py-2.5 text-right text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Amount</th>
                    <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Status</th>
                    <th class="px-4 py-2.5 text-right text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Date</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($recentTransactions as $t)
                <tr data-row-link="{{ route('receipts.show', $t->receipt_number) }}" tabindex="0"
                    class="cursor-pointer transition-colors hover:bg-green-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-green-300">
                    <td class="px-4 py-3 text-sm font-medium text-green-800 whitespace-nowrap">
                        <a href="{{ route('receipts.show', $t->receipt_number) }}">{{ $t->receipt_number }}</a>
                    </td>
                    <td class="max-w-0 truncate px-4 py-3 text-sm text-gray-900" title="{{ $t->receiver_name }}">{{ $t->receiver_name }}</td>
                    <td class="px-4 py-3 text-right text-sm text-gray-900 whitespace-nowrap tabular-nums">{{ \App\Models\Account::format($t->currency, $t->amount) }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs rounded-full
                            @if($t->status === 'completed') bg-green-100 text-green-800
                            @elseif($t->status === 'pending') bg-yellow-100 text-yellow-800
                            @else bg-red-100 text-red-800 @endif">
                            {{ ucfirst($t->status) }}
                        </span>
                    </td>
                    {{-- The day, not the minute: the board asks which bookings are recent and the
                         stamp on the receipt answers to the second. It also matches the other
                         tables, which all write the date the same three words round. --}}
                    <td class="px-4 py-3 text-right text-sm text-gray-500 whitespace-nowrap tabular-nums">{{ $t->created_at->format('M j, Y') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                        No transactions yet.
                        @can('create-transaction') Book the first one from <a href="{{ route('transactions.create') }}" class="text-green-700 underline">Send Money</a>.@endcan
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    const chartData = @json($chartData);
    const brand = 'rgb(22, 101, 52)';

    document.querySelectorAll('[role="tab"][data-window]').forEach((tab) => {
        const tabs = Array.from(document.querySelectorAll('[role="tab"][data-window]'));

        function pick(next, focus) {
            tabs.forEach((t) => {
                const on = t === next;
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                t.tabIndex = on ? 0 : -1;
                document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
            });
            if (focus) next.focus();
        }

        tab.addEventListener('click', () => pick(tab));
        tab.addEventListener('keydown', (event) => {
            const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
            if (!step) return;
            event.preventDefault();
            pick(tabs[(tabs.indexOf(tab) + step + tabs.length) % tabs.length], true);
        });
    });

    // A booking row is the booking. The whole line goes, because five rows a day are read by
    // reaching for the next one rather than aiming at a link two centimetres wide inside it. A
    // click that lands on the receipt link itself is left to do what a link does.
    document.querySelectorAll('tr[data-row-link]').forEach((row) => {
        const open = () => { window.location.href = row.dataset.rowLink; };

        row.addEventListener('click', (event) => {
            if (event.target.closest('a')) return;
            open();
        });
        row.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            open();
        });
    });

    if (document.getElementById('transactionsChart')) {
        new Chart(document.getElementById('transactionsChart'), {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Completed',
                    data: chartData.transactions,
                    borderColor: brand,
                    backgroundColor: 'rgba(22, 101, 52, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }
                }
            }
        });
    }

    if (document.getElementById('revenueChart')) {
        new Chart(document.getElementById('revenueChart'), {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Charge',
                    data: chartData.revenue_ugx,
                    backgroundColor: 'rgba(22, 101, 52, 0.8)',
                    borderColor: brand,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    }

    @can('view-profit')
    if (document.getElementById('profitChart')) {
        new Chart(document.getElementById('profitChart'), {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [
                    {
                        label: 'Charge',
                        data: chartData.revenue_ugx,
                        backgroundColor: 'rgba(22, 101, 52, 0.8)'
                    },
                    {
                        label: 'FX profit',
                        data: chartData.exchange_ugx,
                        backgroundColor: 'rgba(37, 99, 235, 0.8)'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (item) {
                                return item.dataset.label + ': UGX ' + item.parsed.y.toLocaleString('en', {maximumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true }
                }
            }
        });
    }
    @endcan

    // The stamp is right to the minute, so it is redrawn every ten seconds and never shows a
    // running second: a digit that changes each second pulls the eye off the balances. The day is
    // carried beside the time on a wide board and dropped on a phone, where the board is opened
    // many times in the same day.
    //
    // The desk's money is kept in Kampala time, so the clock is too. The zone offset is read off
    // the clock itself rather than taken from the machine, because a laptop set to another region
    // would otherwise print a foreign hour beside figures that are not in it.
    function kampalaNow(from) {
        const stamp = document.getElementById('liveClock');
        const offset = stamp ? parseInt(stamp.dataset.zoneOffset || '180', 10) : 180;
        const base = from || new Date();

        return new Date(base.getTime() + (base.getTimezoneOffset() + offset) * 60000);
    }

    const clockDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const clockMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    function clockWords(now) {
        let h = now.getHours();
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;

        return `${h}:${String(now.getMinutes()).padStart(2, '0')} ${ampm} EAT`;
    }

    function updateClock() {
        const stamp = document.getElementById('liveClock');
        if (!stamp) return;

        const now = kampalaNow();
        const time = clockWords(now);
        const text = window.matchMedia('(min-width: 640px)').matches
            ? `${clockDays[now.getDay()]}, ${now.getDate()} ${clockMonths[now.getMonth()]} ${now.getFullYear()} · ${time}`
            : time;

        if (stamp.textContent !== text) stamp.textContent = text;

        // The age on a balance rides the same tick, so the two can never disagree while the page
        // sits open.
        if (window.refreshStamps) window.refreshStamps();
    }
    updateClock();
    setInterval(updateClock, 10000);
    window.addEventListener('resize', updateClock);

    // A reload gives nothing back but a spinner for the moment before the page is gone, so the
    // moment is kept across the reload and said out loud once the fresh figures land.
    const refreshedKey = 'pluspay.dashboard.lastRefreshed';

    function rememberRefresh() {
        try { sessionStorage.setItem(refreshedKey, String(Date.now())); } catch (error) { return; }
    }

    function sayWhenItWasRefreshed() {
        const line = document.getElementById('lastRefreshed');
        if (!line) return;

        let at = null;
        try { at = sessionStorage.getItem(refreshedKey); } catch (error) { return; }
        if (!at) return;

        line.textContent = 'Last refreshed ' + clockWords(kampalaNow(new Date(Number(at))));
    }

    // The button is disabled and spun before the reload so the click is never silent, and a
    // second click cannot queue a second reload. The short pause lets that state paint first.
    function reloadDashboard(button) {
        rememberRefresh();
        button.disabled = true;
        button.classList.add('opacity-60', 'cursor-wait');
        button.querySelector('span').textContent = 'Reloading...';
        // On a phone the word is dropped and only the glyph is shown, so the state has to be
        // carried by the name the button is read under as well.
        button.setAttribute('aria-label', 'Reloading this board');
        button.querySelector('svg').classList.add('animate-spin');
        setTimeout(function () { window.location.reload(); }, 150);
    }

    sayWhenItWasRefreshed();

    // The desk search offers exactly the pages the sidebar shows this account, because the index
    // is read off the sidebar's own links. A role change needs no second list here, and a link a
    // permission gate hides can never be offered by the box.
    (function () {
        const box = document.querySelector('[data-dashboard-search]');
        if (!box) return;

        const input = document.getElementById('dashboardSearch');
        const panel = document.getElementById('dashboardSearchResults');
        const list = document.getElementById('dashboardSearchList');
        const empty = document.getElementById('dashboardSearchEmpty');

        function plain(node) {
            const clone = node.cloneNode(true);
            clone.querySelectorAll('svg, span[class*="rounded-full"]').forEach(function (el) { el.remove(); });
            return clone.textContent.trim().replace(/\s+/g, ' ');
        }

        const pages = Array.from(document.querySelectorAll('#appSidebar nav a[href]'))
            .filter(function (link) { return !link.getAttribute('href').startsWith('#'); })
            .map(function (link) {
                const holder = link.closest('div[id$="Group"]');
                const group = holder && holder.previousElementSibling ? plain(holder.previousElementSibling) : '';
                const label = plain(link);

                return { label: label, group: group, href: link.getAttribute('href') };
            })
            .filter(function (page) { return page.label !== ''; });

        function rank(page, q) {
            const label = page.label.toLowerCase();
            if (label === q) return 0;
            if (label.startsWith(q)) return 1;
            if (label.includes(q)) return 2;
            if (page.group.toLowerCase().includes(q)) return 3;
            return -1;
        }

        let active = -1;

        function paintActive() {
            Array.from(list.children).forEach(function (li, i) {
                li.classList.toggle('bg-green-50', i === active);
            });
        }

        function render() {
            const q = input.value.trim().toLowerCase();

            list.innerHTML = '';
            active = -1;

            let hits = [];
            if (q !== '') {
                hits = pages
                    .map(function (page) { return [rank(page, q), page]; })
                    .filter(function (pair) { return pair[0] >= 0; })
                    .sort(function (a, b) { return a[0] - b[0] || a[1].label.localeCompare(b[1].label); })
                    .map(function (pair) { return pair[1]; })
                    .slice(0, 8);
            }

            hits.forEach(function (page, i) {
                const li = document.createElement('li');
                li.id = 'search-result-' + i;
                li.setAttribute('role', 'option');
                li.dataset.href = page.href;
                li.className = 'px-4 py-2.5 flex items-baseline gap-3 cursor-pointer hover:bg-green-50';

                const name = document.createElement('span');
                name.className = 'text-sm font-medium text-gray-900 truncate';
                name.textContent = page.label;

                const where = document.createElement('span');
                where.className = 'text-xs text-gray-500 ml-auto shrink-0';
                where.textContent = page.group || 'Page';

                li.appendChild(name);
                li.appendChild(where);
                li.addEventListener('click', function () { window.location.href = page.href; });
                list.appendChild(li);
            });

            empty.classList.toggle('hidden', hits.length > 0);
            list.classList.toggle('hidden', hits.length === 0);
            panel.classList.toggle('hidden', q === '');
            input.setAttribute('aria-expanded', q === '' ? 'false' : 'true');
        }

        function go() {
            const items = Array.from(list.children);
            const target = items[active] || items[0];
            if (target && target.dataset.href) window.location.href = target.dataset.href;
        }

        input.addEventListener('input', render);
        input.addEventListener('focus', function () { if (input.value.trim() !== '') panel.classList.remove('hidden'); });
        input.addEventListener('keydown', function (event) {
            const items = Array.from(list.children);

            if (event.key === 'ArrowDown') { event.preventDefault(); active = Math.min(active + 1, items.length - 1); paintActive(); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); active = Math.max(active - 1, 0); paintActive(); }
            else if (event.key === 'Enter') { event.preventDefault(); go(); }
            // Escape is the way out of the box: the list closes, what was typed is dropped and the
            // hands leave the field, so a board escaped from is not left holding a stale query.
            else if (event.key === 'Escape') {
                panel.classList.add('hidden');
                input.setAttribute('aria-expanded', 'false');
                input.value = '';
                input.blur();
            }
        });

        document.addEventListener('click', function (event) {
            if (!box.contains(event.target)) {
                panel.classList.add('hidden');
                input.setAttribute('aria-expanded', 'false');
            }
        });

        // The slash opens the box from anywhere on the board, unless the hands are already
        // typing somewhere.
        document.addEventListener('keydown', function (event) {
            if (event.key !== '/') return;
            const target = event.target;
            if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable)) return;
            event.preventDefault();
            input.focus();
            input.select();
        });
    })();

</script>
@endsection

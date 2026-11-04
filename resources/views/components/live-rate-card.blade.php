{{-- Two lines, not a screen. The counter price is the figure every desk books at, so it leads at
     headline size; the market it stands on is the reference underneath, in grey, at the size of a
     footnote. This used to be a tile per pair with a sparkline and a sentence of change under each,
     and the whole board was pushed three screens down behind it. --}}
<section aria-labelledby="dashboard-rate-heading" class="flex h-full flex-col rounded-xl border border-gray-200 bg-white shadow-sm p-4">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
        <h2 id="dashboard-rate-heading" class="text-xs font-semibold uppercase tracking-wide text-gray-500">Exchange Rate</h2>
        @if($stale)
        {{-- A pull that has stopped is the one thing on this card that needs doing something about,
             so it is a badge that cannot be skimmed past rather than a sentence in the grey text
             below it. The age is printed because a two-day gap and a three-week gap are different
             faults. --}}
        <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-700 ring-1 ring-inset ring-red-200">
            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            Market pull stopped{{ $staleSince ? ' '.$staleSince : ' (the pull has never run)' }}
        </span>
        @endif
        @can('view-exchange-rates')
        <a href="{{ route('exchange-rates.index') }}" class="ml-auto inline-flex items-center gap-1 text-xs font-semibold text-green-800 hover:underline">
            Rate history
            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
        </a>
        @endcan
    </div>

    @php
        // One line per price, both pairs on it, in the order the desk meets them: shillings then
        // yuan. A pair with no price in use says so in place of its figure instead of going quiet,
        // because a line with only one half of it is the sort of thing that gets read as a typo.
        $line = fn (array $figures) => collect($figures)
            ->map(fn (?string $value, string $quote) => ($value === null ? 'no '.\App\Models\Account::currencyLabel($quote).' price' : $value.' '.$quote.'/USD'))
            ->implode(' · ');
    @endphp

    @if(($desk['UGX'] ?? null) === null && ($desk['RMB'] ?? null) === null)
    <p class="mt-2 text-sm text-gray-500">No price is in use.</p>
    @else
    <p class="mt-2 text-sm leading-snug text-gray-900">
        <span class="font-semibold text-gray-500">Desk rate</span>
        <span class="tabular-nums font-semibold">{{ $line($desk) }}</span>
    </p>
    @endif

    @if($showMarket)
    @if($market === [])
    <p class="mt-1 text-xs text-gray-500">No live market reading yet.</p>
    @else
    {{-- The market sits in grey at footnote size on purpose. It is a reference, not the price
         anybody is charged, and printed beside the desk rate at the same weight it reads as a
         second counter price. --}}
    <p class="mt-1 text-xs leading-snug text-gray-500">
        <span class="tabular-nums">Market {{ $line(collect($market)->mapWithKeys(fn (array $reading, string $quote) => [$quote => $reading['value']])->all()) }}</span>
        @php($moves = collect($market)->pluck('change')->filter()->values())
        @if($moves->isNotEmpty())
        <span class="text-gray-400">· {{ $moves->implode(', ') }}</span>
        @endif
    </p>
    <p class="mt-1 text-[11px] text-gray-400">
        Mid-market, not the counter price. Pulled {{ $lastPulled?->format('j M, H:i') }} from {{ implode(' and ', $sources) }}.
    </p>
    @endif
    @endif
</section>

@extends('layouts.app')

@section('title', 'Exchange Rates')
@section('inline-validation', true)

@section('content')
@php
    $editing = (int) old('rate_id');
    $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 6, '.', ','), '0'), '.');
    $input = fn ($v) => rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');
@endphp

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <div class="flex items-center gap-2">
            <h1 class="text-2xl font-bold text-gray-900">Exchange Rates</h1>
            <details class="relative">
                <summary aria-label="About rate history" class="list-none cursor-pointer rounded text-gray-600">&#9432;</summary>
                <p class="absolute right-0 top-7 z-20 w-64 rounded-lg border bg-white p-3 text-sm text-gray-700 shadow-lg">Rates are never deleted. Changing a rate does not affect past transfers.</p>
            </details>
        </div>
        <p class="mt-1 text-sm text-gray-600">Rate history. Set new rates in System Settings.</p>
    </div>
    <div class="flex gap-2">
        @can('view-settings')
        <a href="{{ route('settings.index') }}" class="rounded-md bg-gray-200 px-4 py-2 text-sm text-gray-800 hover:bg-gray-300">Update rates</a>
        @endcan
        @can('manage-exchange-rates')
        <a href="{{ route('exchange-rates.pdf', request()->query()) }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Download PDF</a>
        <a href="{{ route('export.exchange-rates', request()->query()) }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Export Excel</a>
        <a href="{{ route('exchange-rates.print', request()->query()) }}" target="_blank" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Print</a>
        @endcan
    </div>
</div>

<div class="mb-6 rounded-lg bg-white p-4 shadow">
    <form method="GET" action="{{ route('exchange-rates.index') }}" class="rate-filters flex flex-wrap items-start gap-2">
        <div class="min-w-40 flex-1">
            <input aria-label="Search currency or name" type="search" name="search" value="{{ request('search') }}" placeholder="Search currency or name" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
        </div>
        <div>
            <select aria-label="Currency" name="currency" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                <option value="">All currencies</option>
                @foreach(['USD', 'UGX', 'RMB'] as $currency)
                <option value="{{ $currency }}" @selected(request('currency') === $currency)>{{ $currency }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <select aria-label="Status" name="status" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                <option value="active" @selected(request('status') === 'active')>In use</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Earlier</option>
                <option value="" @selected((string) request('status') === '')>All</option>
            </select>
        </div>
        <div>
            <select aria-label="Added by" name="created_by" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                <option value="">Added by anyone</option>
                @foreach($creators as $creator)
                <option value="{{ $creator->id }}" @selected(request('created_by') == $creator->id)>{{ $creator->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="rate-dates flex min-w-0 flex-col gap-1">
            <div class="flex items-center gap-1">
                <input aria-label="From date" type="date" name="date_from" value="{{ request('date_from') }}" class="min-w-0 w-32 rounded-md border border-gray-300 px-2 py-2 text-sm">
                <span class="text-xs text-gray-600">to</span>
                <input aria-label="To date" type="date" name="date_to" value="{{ request('date_to') }}" class="min-w-0 w-32 rounded-md border border-gray-300 px-2 py-2 text-sm">
            </div>
            <div class="flex flex-wrap gap-1">
                @php
                    $quickBase = request()->only(['search', 'currency', 'created_by']);
                    $quickBase['status'] = (string) request('status');
                    $ranges = [
                        'Today' => [today()->toDateString(), today()->toDateString()],
                        'Yesterday' => [today()->subDay()->toDateString(), today()->subDay()->toDateString()],
                        'This month' => [now()->startOfMonth()->toDateString(), now()->toDateString()],
                        'Last month' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
                    ];
                @endphp
                @foreach($ranges as $label => [$from, $to])
                <a href="{{ route('exchange-rates.index', array_merge($quickBase, ['date_from' => $from, 'date_to' => $to])) }}" class="rounded px-1.5 py-1 text-xs {{ request('date_from') === $from && request('date_to') === $to ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
        <button type="submit" class="rounded-md bg-green-800 px-3 py-2 text-sm text-white hover:bg-green-700">Filter</button>
        <a href="{{ route('exchange-rates.index') }}" class="rounded-md bg-gray-200 px-3 py-2 text-sm text-gray-800 hover:bg-gray-300">Clear</a>
    </form>
</div>

@if(!empty($unpricedPairs) || $outsideRule->isNotEmpty())
<div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4">
    <h2 class="text-sm font-semibold text-amber-900">Prices in use</h2>
    <ul class="mt-1 space-y-1">
        @foreach($outsideRule as $stray)
        <li class="text-sm text-amber-900">{{ $stray->from_currency.' → '.$stray->to_currency }} {{ $trim($stray->sell_rate ?? $stray->rate) }} is not the price being read. Take it out of use.</li>
        @endforeach
        @if(!empty($unpricedPairs))
        <li class="text-sm text-amber-900">No price is in use for {{ implode(' and ', $unpricedPairs) }}.</li>
        @endif
    </ul>
</div>
@endif

@if(!empty($spreadWarnings))
<div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
    <h2 class="text-sm font-semibold text-red-800">Spread warning</h2>
    <ul class="mt-1 space-y-1">
        @foreach($spreadWarnings as $warning)
        <li class="text-sm text-red-700">{{ $warning }}</li>
        @endforeach
    </ul>
</div>
@endif

<div class="mb-6 rounded-lg bg-white p-4 shadow">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-lg font-semibold text-gray-900">Live market rate</h2>
            <p class="mt-1 text-sm text-gray-600">The dollar's mid-market price. Pull it to see the figure, then use a reading only when you want it to become a price.</p>
        </div>
        @can('manage-exchange-rates')
        <form method="POST" action="{{ route('exchange-rates.market.pull') }}">
            @csrf
            <button type="submit" class="shrink-0 rounded-md border border-green-700 px-4 py-2 text-sm font-medium text-green-800 hover:bg-green-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-700">Pull now</button>
        </form>
        @endcan
    </div>

    @error('market_rate')
    <p role="alert" class="mt-3 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>
    @enderror

    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
        @foreach($market['pairs'] as $quote => $pair)
        <div class="min-w-0 rounded-lg border border-gray-200 p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-sm font-medium text-gray-700">{{ $pair['label'] }} <span class="text-gray-500">({{ $pair['unit'] }})</span></h3>
                    @if($pair['latest'])
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $pair['display'] }}</p>
                    <p class="mt-1 text-xs {{ $pair['tone'] }}">{{ $pair['change_text'] }}</p>
                    @else
                    <p class="mt-1 text-lg font-semibold text-gray-500">No reading yet</p>
                    <p class="mt-1 text-xs text-gray-500">Pull the rate now to start the record.</p>
                    @endif
                </div>
                @if($pair['points'])
                <svg viewBox="0 0 120 36" class="h-10 w-28 shrink-0 {{ $pair['line'] }}" role="img" aria-label="{{ $pair['chart_label'] }}" preserveAspectRatio="none">
                    <polyline points="{{ $pair['points'] }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                </svg>
                @endif
            </div>
            @if($pair['latest'])
            <p class="mt-2 text-xs text-gray-500">Latest reading for {{ $pair['latest']->rate_date->format('d M Y') }}, pulled {{ $pair['latest']->fetched_at->format('h:i A') }} from {{ $pair['latest']->source }}.</p>
            @can('manage-exchange-rates')
            <form method="POST" action="{{ route('exchange-rates.market.use') }}" class="mt-3" data-confirm-title="Use this market reading" data-confirm="Set the USD to {{ $quote }} {{ $quote === 'UGX' ? 'buying' : 'selling' }} price to {{ $pair['display'] }}? The {{ $quote === 'UGX' ? 'selling' : 'buying' }} price stays as it is, a new price row is added, and past transfers do not change." data-confirm-ok="Use reading">
                @csrf
                <input type="hidden" name="quote" value="{{ $quote }}">
                <button type="submit" class="rounded-md bg-green-800 px-3 py-2 text-sm font-medium text-white hover:bg-green-700">Use as {{ $quote === 'UGX' ? 'buying' : 'selling' }} price</button>
            </form>
            @endcan
            @endif
        </div>
        @endforeach
    </div>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-3">
        <p class="text-xs {{ $market['stale_pull'] ? 'font-medium text-amber-700' : 'text-gray-500' }}">
            @if($market['last_pulled'])
            Last pulled {{ $market['last_pulled']->format('d M Y, h:i A') }}. The system pulls every hour.
            @else
            Never pulled. The system pulls every hour once the schedule runs.
            @endif
        </p>
        <details class="text-xs text-gray-600">
            <summary class="cursor-pointer font-medium text-green-800">Where this rate comes from</summary>
            <p class="mt-2 max-w-xl">The system asks Wise for the live mid-market price first. If Wise cannot be reached it falls back to open daily feeds, which can lag by a day or more. Every reading names its source and pull time and is kept on record.</p>
        </details>
    </div>

    @if(!empty($market['history']))
    <details class="mt-3 border-t border-gray-100 pt-3 text-sm text-gray-600">
        <summary class="cursor-pointer font-medium text-green-800">Recent daily readings</summary>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-500">
                        <th scope="col" class="py-1.5 pr-4 font-medium">Day</th>
                        <th scope="col" class="py-1.5 pr-4 font-medium">USD to UGX</th>
                        <th scope="col" class="py-1.5 pr-4 font-medium">USD to RMB</th>
                        <th scope="col" class="py-1.5 font-medium">Pulled</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($market['history'] as $day)
                    <tr class="{{ $loop->first ? 'bg-green-50/50' : '' }}">
                        <td class="py-2 pr-4 text-gray-900">{{ $day['date']->format('d M Y') }}</td>
                        @foreach(['UGX', 'RMB'] as $quote)
                        <td class="py-2 pr-4">
                            <p class="tabular-nums text-gray-900">{{ $day[$quote] }}</p>
                            @if($day['move'][$quote] === 'flat')
                            <p class="text-[11px] text-gray-400">No change</p>
                            @elseif($day['move'][$quote] !== null)
                            <p class="text-[11px] tabular-nums {{ str_starts_with($day['move'][$quote], '+') ? 'text-green-700' : 'text-red-700' }}">{{ str_starts_with($day['move'][$quote], '+') ? '▲' : '▼' }} {{ ltrim($day['move'][$quote], '+-') }}</p>
                            @endif
                        </td>
                        @endforeach
                        <td class="py-2 text-gray-500">{{ $day['pulled']->format('d M Y, h:i A') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-500">One line per market day, newest first. The small line under each price is its move from the day before.</p>
    </details>
    @endif
</div>

<div class="rates-wrap overflow-x-auto rounded-lg bg-white shadow">
    <table class="rates-table min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                @foreach(['Pair', 'Selling', 'Buying', 'Margin', 'Status', 'Added', 'Updated', 'Actions'] as $heading)
                <th scope="col" class="px-3 py-3 text-xs font-medium uppercase tracking-wide text-gray-600 {{ $heading === 'Actions' ? 'text-right rate-actions' : 'text-left' }}">{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            @forelse($rates as $rate)
            @php
                $pair = $rate->from_currency.' to '.$rate->to_currency;
                $selling = $trim($rate->sell_rate ?? $rate->rate);
                $margin = $rate->buy_rate === null ? null : ((float) ($rate->sell_rate ?? $rate->rate) - (float) $rate->buy_rate) * ($rate->from_currency === 'USD' && $rate->to_currency === 'RMB' ? -1 : 1);
            @endphp
            <tr class="rate-row hover:bg-gray-50">
                <td class="whitespace-nowrap px-3 py-4 text-sm font-medium text-gray-900" data-label="Pair">{{ $rate->from_currency }} &rarr; {{ $rate->to_currency }}</td>
                <td class="px-3 py-4 text-left text-sm tabular-nums text-gray-900" data-label="Selling">{{ $selling }}</td>
                <td class="px-3 py-4 text-left text-sm tabular-nums text-gray-900" data-label="Buying">{{ $rate->buy_rate === null ? '-' : $trim($rate->buy_rate) }}</td>
                <td class="px-3 py-4 text-left text-sm tabular-nums {{ $margin === null ? 'text-gray-900' : ($margin < 0 ? 'text-red-700' : 'text-green-800') }}" data-label="Margin" title="Earned per dollar">{{ $margin === null ? '-' : ($margin > 0 ? '+' : '').$trim($margin) }}</td>
                <td class="px-3 py-4" data-label="Status"><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $rate->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">{{ $rate->is_active ? 'In use' : 'Earlier' }}</span></td>
                <td class="px-3 py-4 text-sm" data-label="Added">
                    <div><div class="text-gray-900">{{ $rate->creator?->name ?? '-' }}</div><time datetime="{{ $rate->created_at->toIso8601String() }}" title="{{ $rate->created_at->format('M d, Y H:i:s') }}" class="text-xs text-gray-600 whitespace-nowrap">{{ $rate->created_at->format('M d, Y h:i A') }}</time>
                        @if($rate->effective_from && $rate->effective_from->toDateString() !== $rate->created_at->toDateString())
                            <div class="text-xs text-gray-600">In force from {{ $rate->effective_from->format('M d, Y') }}</div>
                        @endif</div>
                </td>
                <td class="px-3 py-4 text-sm" data-label="Updated">
                    <div><div class="text-gray-900">{{ $rate->editor?->name ?? '-' }}</div><time datetime="{{ $rate->updated_at->toIso8601String() }}" title="{{ $rate->updated_at->format('M d, Y H:i:s') }}" class="text-xs text-gray-600 whitespace-nowrap">{{ $rate->updated_at->format('M d, Y h:i A') }}</time></div>
                </td>
                <td class="rate-actions px-3 py-4">
                    @can('manage-exchange-rates')
                    <div class="flex items-center justify-end gap-1">
                        <button type="button" data-rate-edit="{{ $rate->id }}" onclick="toggleRateEdit({{ $rate->id }})" aria-controls="rate-edit-{{ $rate->id }}" aria-expanded="{{ $editing === $rate->id ? 'true' : 'false' }}" title="Edit the {{ $pair }} prices" aria-label="Edit the {{ $pair }} prices" class="inline-flex h-8 shrink-0 items-center justify-center gap-1.5 rounded-md px-2 text-gray-700 hover:bg-green-50 hover:text-green-800 focus-visible:ring-2 focus-visible:ring-green-600 md:w-8 md:px-0">
                            <span class="md:hidden text-sm">Edit</span>
                            <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        </button>
                        @if($rate->is_active)
                        <form method="POST" action="{{ route('exchange-rates.toggle', $rate) }}" data-confirm-title="Take this price out of use" data-confirm="Take {{ $pair }} {{ $selling }} out of use?" data-confirm-ok="Take out of use">
                            @csrf
                            @method('PATCH')
                            <button type="submit" title="Take {{ $pair }} {{ $selling }} out of use" aria-label="Take {{ $pair }} {{ $selling }} out of use" class="inline-flex h-8 shrink-0 items-center justify-center gap-1.5 rounded-md px-2 text-gray-700 hover:bg-red-50 hover:text-red-700 focus-visible:ring-2 focus-visible:ring-green-600 md:w-8 md:px-0">
                                <span class="md:hidden text-sm">Take out of use</span>
                                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </button>
                        </form>
                        @elseif(\App\Models\ExchangeRate::isDeskPair($rate->from_currency, $rate->to_currency))
                        <form method="POST" action="{{ route('exchange-rates.toggle', $rate) }}" data-confirm-title="Set the current rate" data-confirm="Set {{ $pair }} {{ $selling }} as the current rate?" data-confirm-ok="Set as current">
                            @csrf
                            @method('PATCH')
                            <button type="submit" title="Set {{ $pair }} {{ $selling }} as the current rate" aria-label="Set {{ $pair }} {{ $selling }} as the current rate" class="inline-flex h-8 shrink-0 items-center justify-center gap-1.5 rounded-md px-2 text-green-700 hover:bg-green-50 hover:text-green-800 focus-visible:ring-2 focus-visible:ring-green-600 md:w-8 md:px-0">
                                <span class="md:hidden text-sm">Set as current</span>
                                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </button>
                        </form>
                        @endif
                    </div>
                    @else
                    <span class="block text-right text-sm text-gray-600">-</span>
                    @endcan
                </td>
            </tr>
            @can('manage-exchange-rates')
            <tr id="rate-edit-{{ $rate->id }}" class="rate-edit {{ $editing === $rate->id ? '' : 'hidden' }} bg-gray-50">
                <td colspan="8" class="px-4 py-4">
                    <form id="edit-rate-{{ $rate->id }}" data-validate data-lock method="POST" action="{{ route('exchange-rates.update', $rate) }}" class="flex flex-wrap items-start gap-4">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="rate_id" value="{{ $rate->id }}">
                        <p class="text-sm font-medium text-gray-900">{{ $rate->from_currency }} &rarr; {{ $rate->to_currency }}</p>
                        @foreach(['sell_rate' => 'Selling', 'buy_rate' => 'Buying'] as $field => $label)
                        <div data-field>
                            <label for="{{ $field }}-{{ $rate->id }}" class="mb-1 block text-sm text-gray-700">{{ $label }}{{ $field === 'sell_rate' ? ' *' : '' }}</label>
                            <input type="number" id="{{ $field }}-{{ $rate->id }}" name="{{ $field }}" step="0.000001" min="0.000001" max="999999.999999" @required($field === 'sell_rate') value="{{ $editing === $rate->id ? old($field) : ($rate->$field === null ? ($field === 'sell_rate' ? $input($rate->rate) : '') : $input($rate->$field)) }}" class="w-40 rounded-md border border-gray-300 px-3 py-2 text-sm">
                            <p data-field-error="{{ $field }}" class="mt-1 text-sm text-red-700">@if($editing === $rate->id){{ $errors->first($field) }}@endif</p>
                        </div>
                        @endforeach
                        @if(!$rate->is_active)
                        <div data-field class="max-w-xs">
                            <label class="flex items-start gap-2 text-sm text-gray-700"><input type="checkbox" required name="history_ok" value="1" @checked(old('history_ok') && $editing === $rate->id) class="mt-1 rounded border-gray-300"><span>I understand this is an earlier price, so correcting it changes how past transfers read back.</span></label>
                            <p data-field-error="history_ok" class="mt-1 text-sm text-red-700">@if($editing === $rate->id){{ $errors->first('history_ok') }}@endif</p>
                        </div>
                        @endif
                        {{-- 24px is the label line (20px) plus its margin (4px), which drops the buttons
                             onto the same line as the two inputs and keeps them there when an error
                             message opens up underneath one. --}}
                        <div class="flex items-center gap-3 mt-6">
                            <button type="submit" class="rounded-md bg-green-800 px-4 py-2 text-sm text-white hover:bg-green-700">Save</button>
                            <button type="button" onclick="toggleRateEdit({{ $rate->id }}, true)" class="rounded-md px-3 py-2 text-sm text-gray-700 hover:bg-gray-200">Cancel</button>
                        </div>
                    </form>
                </td>
            </tr>
            @endcan
            @empty
            <tr><td colspan="8" class="px-6 py-8 text-center text-gray-600">No exchange rates found. @can('view-settings')<a href="{{ route('settings.index') }}" class="text-green-800 underline">Set rates in Settings</a>.@endcan</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($rates->hasPages())<div class="border-t border-gray-200 px-4 py-4">{{ $rates->links() }}</div>@endif
</div>
@endsection

@push('styles')
<style>
    .rate-filters > div:first-child { flex: 1 1 150px; min-width: 150px; }
    .rate-dates input { width: 118px; }
    @media (min-width: 768px) {
        .rates-table .rate-actions { position: sticky; right: 0; width: 96px; min-width: 96px; max-width: 96px; background: white; box-shadow: -8px 0 8px -8px rgb(0 0 0 / 25%); }
        .rates-table th.rate-actions { background: #f9fafb; z-index: 1; }
        .rates-table .rate-row:hover .rate-actions { background: #f9fafb; }
    }
    @media (max-width: 767px) {
        .rate-filters > div:first-child, .rate-dates { flex-basis: 100%; }
        .rate-dates input { flex: 1; }
        .rates-wrap { background: transparent; box-shadow: none; overflow: visible; }
        .rates-table thead { display: none; }
        .rates-table, .rates-table tbody, .rates-table tr, .rates-table td { display: block; width: auto; }
        .rates-table tbody tr { background: white; border-radius: .5rem; margin-bottom: .75rem; padding: .75rem 1rem; border: 1px solid #e5e7eb; }
        .rates-table tbody tr.hidden { display: none; }
        .rates-table .rate-row td { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; padding: .3rem 0; text-align: right; }
        .rates-table .rate-row td[data-label]::before { content: attr(data-label); font-size: .75rem; color: #4b5563; text-align: left; }
        .rates-table td.rate-actions { border-top: 1px solid #e5e7eb; margin-top: .5rem; padding-top: .5rem; justify-content: flex-end; }
        .rate-edit td { padding: 0; }
    }
</style>
@endpush

@push('scripts')
<script>
    function toggleRateEdit(id, forceClose) {
        const row = document.getElementById('rate-edit-' + id);
        const open = row.classList.contains('hidden');
        document.querySelectorAll('.rate-edit').forEach(tr => tr.classList.add('hidden'));
        document.querySelectorAll('[data-rate-edit]').forEach(button => button.setAttribute('aria-expanded', 'false'));
        const button = document.querySelector('[data-rate-edit="' + id + '"]');
        if (open && !forceClose) {
            row.classList.remove('hidden');
            button.setAttribute('aria-expanded', 'true');
            row.querySelector('input[name="sell_rate"]').focus();
        } else button.focus();
    }
</script>
@endpush

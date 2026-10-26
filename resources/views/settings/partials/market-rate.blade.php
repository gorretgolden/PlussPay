@php
    $autoSaved = $market['auto']['on'] ? 'on' : 'off';
    $autoValue = old('market_buy_auto', $autoSaved) === 'on' ? 'on' : 'off';
@endphp

<section aria-labelledby="market-rate-heading" class="mb-5 rounded-lg border border-gray-200 bg-white p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 id="market-rate-heading" class="text-lg font-semibold text-gray-900">Live market rate</h2>
            <p class="mt-1 text-sm text-gray-600">The dollar's mid-market price, pulled by the system and kept on record.</p>
        </div>
        <form method="POST" action="{{ route('settings.market-rates.pull') }}">
            @csrf
            <button type="submit" class="shrink-0 rounded-md border border-green-700 px-4 py-2 text-sm font-medium text-green-800 hover:bg-green-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-700">Pull rate now</button>
        </form>
    </div>

    @error('market_rate')
        <p role="alert" class="mt-4 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>
    @enderror

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
        @foreach ($market['pairs'] as $quote => $pair)
            <div class="min-w-0 rounded-lg border border-gray-200 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-sm font-medium text-gray-700">{{ $pair['label'] }} <span class="text-gray-500">({{ $pair['unit'] }})</span></h3>
                        @if ($pair['latest'])
                            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ $pair['display'] }}</p>
                            <p class="mt-1 text-xs {{ $pair['tone'] }}">{{ $pair['change_text'] }}</p>
                        @else
                            <p class="mt-1 text-lg font-semibold text-gray-500">No reading yet</p>
                            <p class="mt-1 text-xs text-gray-500">Pull the rate now to start the record.</p>
                        @endif
                    </div>
                    @if ($pair['points'])
                        <svg viewBox="0 0 120 36" class="h-10 w-28 shrink-0 {{ $pair['line'] }}" role="img" aria-label="{{ $pair['chart_label'] }}" preserveAspectRatio="none">
                            <polyline points="{{ $pair['points'] }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                        </svg>
                    @endif
                </div>
                @if ($pair['latest'])
                    <p class="mt-3 text-xs text-gray-500">Daily mid-market for {{ $pair['latest']->rate_date->format('d M Y') }}, pulled {{ $pair['latest']->fetched_at->format('d M Y, H:i') }} from {{ $pair['latest']->source }}.</p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-5 border-t border-gray-200 pt-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0 max-w-xl">
                <label for="market_buy_auto" class="block text-sm font-medium text-gray-700">Automatic buying rate</label>
                <p id="help-market_buy_auto" class="mt-1 text-xs text-gray-500">On: each pull sets the USD → UGX buying rate to the market, but only while it stays below your selling rate. The selling rate is never changed.</p>
            </div>
            <div class="shrink-0">
                <select id="market_buy_auto" name="market_buy_auto" form="settings-form-rates" data-original="{{ $autoSaved }}" data-label="Automatic buying rate" aria-describedby="help-market_buy_auto error-market_buy_auto market-buy-auto-note" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm">
                    <option value="off" @selected($autoValue === 'off')>Off</option>
                    <option value="on" @selected($autoValue === 'on')>On</option>
                </select>
                <p id="error-market_buy_auto" data-field-error="market_buy_auto" class="mt-1 text-xs text-red-700">@error('market_buy_auto'){{ $message }}@enderror</p>
            </div>
        </div>
        <p id="market-buy-auto-note" class="mt-2 text-xs {{ $market['auto']['tone'] }}">{{ $market['auto']['text'] }}</p>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-3">
        <p class="text-xs {{ $market['stale_pull'] ? 'font-medium text-amber-700' : 'text-gray-500' }}">
            @if ($market['last_pulled'])
                Last pulled {{ $market['last_pulled']->format('d M Y, H:i') }}. The system pulls every hour.
            @else
                Never pulled. The system pulls every hour once the schedule runs.
            @endif
        </p>
        <details class="text-xs text-gray-600">
            <summary class="cursor-pointer font-medium text-green-800">Where this rate comes from</summary>
            <p class="mt-2 max-w-xl">The system asks Wise for the live mid-market price first. If Wise cannot be reached it falls back to open daily feeds, which can lag the live market by a day or more. Every reading names its source and pull time and is kept on record. The desk's selling rate is never changed automatically.</p>
        </details>
    </div>

    @if (!empty($market['history']))
        <details class="mt-4 text-sm text-gray-600">
            <summary class="cursor-pointer font-medium text-green-800">Recent daily readings</summary>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-xs">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500">
                            <th scope="col" class="py-1.5 pr-4 font-medium">Day</th>
                            <th scope="col" class="py-1.5 pr-4 font-medium">USD → UGX</th>
                            <th scope="col" class="py-1.5 pr-4 font-medium">USD → RMB</th>
                            <th scope="col" class="py-1.5 font-medium">Pulled</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($market['history'] as $day)
                            <tr class="{{ $loop->first ? 'bg-green-50/50' : '' }}">
                                <td class="py-2 pr-4 text-gray-900">{{ $day['date']->format('d M Y') }}</td>
                                @foreach (['UGX', 'RMB'] as $quote)
                                    <td class="py-2 pr-4">
                                        <p class="tabular-nums text-gray-900">{{ $day[$quote] }}</p>
                                        @if ($day['move'][$quote] === 'flat')
                                            <p class="text-[11px] text-gray-400">No change</p>
                                        @elseif ($day['move'][$quote] !== null)
                                            <p class="text-[11px] tabular-nums {{ str_starts_with($day['move'][$quote], '+') ? 'text-green-700' : 'text-red-700' }}">{{ str_starts_with($day['move'][$quote], '+') ? '▲' : '▼' }} {{ ltrim($day['move'][$quote], '+-') }}</p>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="py-2 text-gray-500">{{ $day['pulled']->format('d M, H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500">One line per market day. The small line under each price is its move from the day before.</p>
        </details>
    @endif
</section>

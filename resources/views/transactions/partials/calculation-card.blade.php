@php
    // One card, used by the Payment step and the Review step, so the two can never disagree.
    // Every figure is written by renderCalcPanels() in the page script against these data-c keys.
    $panel = $panel ?? 'live';
    $heading = $heading ?? 'Live Calculation';
@endphp
<div class="rounded-2xl border border-green-200 bg-green-50 p-5 sm:p-6" data-calc-panel="{{ $panel }}">
    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <h4 class="text-sm font-semibold text-green-800">{{ $heading }}</h4>
        <div class="flex items-center gap-4">
            @if (!empty($ratesLink))
                @can('manage-exchange-rates')
                    <a href="{{ route('settings.index') }}" class="text-xs text-green-700 underline hover:text-green-900">Update rates</a>
                @endcan
            @endif
            @if (!empty($collapsible))
                <button type="button" data-c="toggle" aria-expanded="true" aria-controls="calc-steps-{{ $panel }}" class="text-xs text-green-700 underline hover:text-green-900">Hide calculation</button>
            @endif
        </div>
    </div>

    <p class="mt-2 text-sm text-gray-600" data-c="empty">Enter an amount to see the calculation.</p>

    <div class="hidden" data-c="body" id="calc-body-{{ $panel }}">
        <div class="mt-3 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <span class="block text-[11px] font-semibold uppercase tracking-wider text-gray-500">Receiver gets</span>
                <div class="mt-1 text-3xl font-bold leading-none text-purple-700">
                    <span class="font-medium text-purple-400">¥</span><span class="tabular-nums" data-c="rmb">0.00</span>
                </div>
            </div>
            <p class="text-xs text-gray-500 tabular-nums sm:text-right" data-c="rates"></p>
        </div>

        <ol class="mt-5 text-sm" data-c="steps" id="calc-steps-{{ $panel }}">
            <li class="flex gap-3">
                <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-800 text-xs font-bold text-white">1</span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline justify-between gap-4">
                        <span class="text-gray-600" data-c="cash-label">Handed over</span>
                        <span class="font-semibold text-gray-900 tabular-nums" data-c="cash">-</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between gap-4">
                        <span class="text-gray-600" data-c="charge-label">Service charge</span>
                        <span class="font-semibold text-red-600 tabular-nums" data-c="charge">-</span>
                    </div>
                    <div class="mt-2 flex items-baseline justify-between gap-4 border-t-2 border-green-300 pt-2">
                        <span class="text-gray-600" data-c="travels-label">Amount that travels</span>
                        <span class="font-bold text-gray-900 tabular-nums" data-c="travels">-</span>
                    </div>
                </div>
            </li>
            <li class="mt-3 flex gap-3 border-t border-green-200 pt-3">
                <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-800 text-xs font-bold text-white">2</span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline justify-between gap-4">
                        <span class="text-gray-600" data-c="usd-label">Converted to USD</span>
                        <span class="font-bold text-gray-900 tabular-nums" data-c="usd">-</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 tabular-nums" data-c="usd-hint"></p>
                </div>
            </li>
            <li class="mt-3 flex gap-3 border-t border-green-200 pt-3">
                <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-800 text-xs font-bold text-white">3</span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline justify-between gap-4">
                        <span class="text-gray-600" data-c="rmb-label">Converted to RMB</span>
                        <span class="font-bold text-gray-900 tabular-nums" data-c="rmb-line">-</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 tabular-nums" data-c="rmb-hint"></p>
                </div>
            </li>
        </ol>
    </div>
</div>

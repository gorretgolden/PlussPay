@extends('layouts.app')

@section('title', 'Hand in Cash')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Hand in Cash</h1>
        <p class="text-gray-600 mt-1">Tick the transfers whose cash is in your hand, then send the request to your lead.</p>
    </div>

    @if($groups->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white p-8 text-center shadow-sm">
            <p class="text-sm font-semibold text-gray-900">No cash in hand</p>
            <p class="mt-1 text-sm text-gray-500">Every completed transfer is already handed in or on its way.</p>
            <a href="{{ route('transactions.index') }}" class="mt-4 inline-block rounded-md bg-green-800 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">See my transfers</a>
        </div>
    @else
        <form method="POST" action="{{ route('cash-handovers.from-transactions') }}" data-lock data-lock-label="Sending..." class="rounded-xl border border-gray-200 bg-white shadow-sm">
            @csrf

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-3 w-10"><span class="sr-only">Tick</span></th>
                            <th class="px-3 py-3">Receipt</th>
                            <th class="px-3 py-3">Route</th>
                            <th class="px-3 py-3">Taken</th>
                            <th class="px-3 py-3 text-right">Money that travels</th>
                            <th class="px-3 py-3 text-right pr-4">Charge</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($groups as $gi => $group)
                        @php
                            $currency = $group['currency'];
                            $checkedByDefault = count($groups) === 1 || $gi === 0;
                            $principal = $group['rows']->reduce(fn ($carry, $t) => bcadd($carry, (string) $t->amount, 2), '0.00');
                            $charges = $group['rows']->reduce(
                                fn ($carry, $t) => $t->isToUganda() ? $carry : bcadd($carry, (string) $t->charge_ugx, 2),
                                '0.00',
                            );
                            $fmt = fn ($value) => \App\Models\Account::format($currency, $value);
                        @endphp
                        <tr class="bg-green-50/60">
                            <td colspan="6" class="px-4 py-2 text-xs font-semibold text-green-900">
                                {{ $currency }} money
                                <span class="font-normal text-green-800/80">({{ \Illuminate\Support\Str::plural('transfer', $group['rows']->count()) }}: {{ $group['rows']->count() }}, {{ $fmt($principal) }} travels, {{ \App\Models\Account::format('UGX', $charges) }} in charges)</span>
                            </td>
                        </tr>
                        @foreach($group['rows'] as $t)
                        <tr>
                            <td class="px-4 py-3">
                                <input type="checkbox" name="transaction_ids[]" value="{{ $t->id }}"
                                    class="tick-row h-4 w-4 rounded border-gray-300 text-green-800 focus:ring-green-600"
                                    data-currency="{{ $currency }}"
                                    data-principal="{{ $t->amount }}"
                                    data-charge="{{ $t->isToUganda() ? 0 : $t->charge_ugx }}"
                                    aria-label="Tick transfer {{ $t->receipt_number }}"
                                    @checked($checkedByDefault)>
                            </td>
                            <td class="px-3 py-3 font-medium text-green-800 whitespace-nowrap">{{ $t->receipt_number }}</td>
                            <td class="px-3 py-3 text-gray-900">{{ $t->sender_name }} <span class="text-gray-400">to</span> {{ $t->receiver_name }}</td>
                            <td class="px-3 py-3 text-gray-500 whitespace-nowrap">{{ $t->created_at->format('M d, Y h:i A') }}</td>
                            <td class="px-3 py-3 text-right text-gray-900 tabular-nums whitespace-nowrap">{{ $fmt($t->amount) }}</td>
                            <td class="px-3 py-3 pr-4 text-right text-gray-500 tabular-nums whitespace-nowrap">{{ \App\Models\Account::format('UGX', $t->isToUganda() ? 0 : $t->charge_ugx) }}</td>
                        </tr>
                        @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

            @php
                $initial = $groups->first();
                $initialPrincipal = $initial['rows']->reduce(fn ($carry, $t) => bcadd($carry, (string) $t->amount, 2), '0.00');
                $initialCharges = $initial['rows']->reduce(
                    fn ($carry, $t) => $t->isToUganda() ? $carry : bcadd($carry, (string) $t->charge_ugx, 2),
                    '0.00',
                );
                $initialSummary = 'You are handing in '
                    . \App\Models\Account::format($initial['currency'], $initialPrincipal)
                    . ' travels'
                    . (bccomp($initialCharges, '0.00', 2) > 0 ? ' with ' . \App\Models\Account::format('UGX', $initialCharges) . ' in charges' : '')
                    . '.';
            @endphp
            <div class="border-t border-gray-100 px-4 py-4 sm:px-6">
                <div class="rounded-lg bg-gray-50 px-4 py-3">
                    <dt class="sr-only">What you are handing in</dt>
                    <p id="handInSummary" class="text-sm text-gray-700">{{ $initialSummary }}</p>
                    <p id="mixedNotice" class="mt-1 hidden text-sm font-medium text-amber-700">One hand-in carries one money. Untick one side to continue.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 border-t border-gray-100 px-4 py-4 sm:px-6 sm:grid-cols-2">
                <div>
                    @if($superAgents->isEmpty())
                        <p class="text-sm font-medium text-amber-700">No active lead agent is set up yet, so there is nobody to send the request to. Ask the office to add one.</p>
                    @else
                        <label for="hand_in_super_agent" class="block text-sm font-medium text-gray-700 mb-1">Handing to <span class="text-red-600">*</span></label>
                        <select id="hand_in_super_agent" name="super_agent_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-500 focus:ring-green-500">
                            <option value="">Choose the super agent</option>
                            @foreach($superAgents as $lead)
                            <option value="{{ $lead->id }}" @selected(old('super_agent_id') == $lead->id)>{{ $lead->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
                <div>
                    <label for="hand_in_note" class="block text-sm font-medium text-gray-700 mb-1">Note <span class="font-normal text-gray-400">(optional)</span></label>
                    <input type="text" id="hand_in_note" name="note" maxlength="255" value="{{ old('note') }}" placeholder="e.g. Counter cash up to today"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-500 focus:ring-green-500">
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-gray-100 px-4 py-4 sm:px-6">
                <a href="{{ route('cash-handovers.index') }}" class="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">Keep the cash</a>
                <button type="submit" id="sendButton" @disabled($superAgents->isEmpty())
                    class="rounded-md bg-green-800 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed">Send for approval</button>
            </div>
        </form>
    @endif
</div>

<script>
(function () {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.tick-row'));
    var summary = document.getElementById('handInSummary');
    var mixedNotice = document.getElementById('mixedNotice');
    var sendButton = document.getElementById('sendButton');

    function money(text) {
        var parts = text.split(' ');
        var number = parseFloat(parts[parts.length - 1].replace(/,/g, ''));
        return number;
    }

    function format(currency, value) {
        if (currency === 'UGX') {
            return 'UGX ' + new Intl.NumberFormat('en-US').format(Math.round(value));
        }
        var symbol = currency === 'USD' ? '$' : (currency === 'RMB' ? '¥' : currency + ' ');
        return symbol + value.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function redraw() {
        var totals = {};
        boxes.forEach(function (box) {
            if (!box.checked) return;
            var currency = box.dataset.currency;
            totals[currency] = totals[currency] || { principal: 0, charge: 0 };
            totals[currency].principal += money(box.dataset.principal);
            totals[currency].charge += money(box.dataset.charge);
        });

        var currencies = Object.keys(totals);
        var parts = [];

        currencies.forEach(function (currency) {
            var principal = format(currency, totals[currency].principal);
            var charge = format('UGX', totals[currency].charge);
            parts.push(principal + ' travels' + (totals[currency].charge > 0 ? ' with ' + charge + ' in charges' : ''));
        });

        if (parts.length === 0) {
            summary.textContent = 'Nothing ticked yet.';
            mixedNotice.classList.add('hidden');
            sendButton.disabled = true;
            return;
        }

        summary.textContent = 'You are handing in ' + parts.join(', and ') + '.';
        var mixed = currencies.length > 1;
        mixedNotice.classList.toggle('hidden', !mixed);
        sendButton.disabled = mixed;
    }

    boxes.forEach(function (box) {
        box.addEventListener('change', redraw);
    });

    redraw();
})();
</script>
@endsection

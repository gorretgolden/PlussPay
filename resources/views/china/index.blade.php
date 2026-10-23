@extends('layouts.app')

@section('title', 'China Desk')

@section('content')
@php
    $usdTo = $outbound->sum('total_usd_equivalent');
    $sendForm = $canSend && $outbound->isNotEmpty();
    $payForm = $canPay && $inbound->isNotEmpty();
@endphp

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">China Desk</h1>
    <p class="text-gray-600 text-sm max-w-3xl">A transfer is not finished when the cash is collected. It is finished when the dollars that transfer was worth arrive at a desk in China and that desk
        hands the receiver the money they were promised, in yuan or in dollars. Tick the rows on each side at the moment the money actually moves, and the books follow.</p>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @if($canSend)
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Cash taken, not yet sent</p>
        @include('china.partials.payout-figures', ['payouts' => $outboundPayouts])
        <p class="text-xs text-gray-500 mt-1">{{ number_format($outbound->count()) }} transfer{{ $outbound->count() === 1 ? '' : 's' }} still in Uganda, worth {{ \App\Models\Account::format('USD', $usdTo) }}</p>
    </div>
    @endif
    @if($canPay)
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Waiting to be paid</p>
        @include('china.partials.payout-figures', ['payouts' => $inboundPayouts])
        <p class="text-xs text-gray-500 mt-1">{{ number_format($inbound->count()) }} transfer{{ $inbound->count() === 1 ? '' : 's' }} waiting to be handed over</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Paid</p>
        @include('china.partials.payout-figures', ['payouts' => $paid['payouts']])
        <p class="text-xs text-gray-500 mt-1">{{ number_format($paid['count']) }} transfer{{ $paid['count'] === 1 ? '' : 's' }} closed on both sides</p>
    </div>
    @endif
    @if($rmbWallet)
    <div class="col-span-2 bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Your yuan wallet</p>
        <p class="text-xl font-semibold {{ (float) $rmbWallet->balance() < 0 ? 'text-red-700' : 'text-gray-900' }} tabular-nums">{{ \App\Models\Account::format('RMB', $rmbWallet->balance()) }}</p>
        <x-wallet-updated :at="$rmbWallet->lastMovementAt()" label="Yuan wallet updated" class="mt-1" />
        <p class="text-xs text-gray-500 mt-2">{{ \App\Models\Account::format('USD', $usdWallet->balance()) }} in your dollar wallet</p>
        <x-wallet-updated :at="$usdWallet->lastMovementAt()" label="Dollar wallet updated" class="mt-0.5" />
    </div>
    @endif
</div>

@if($desks->isEmpty())
<div class="rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800 mb-6">
    Nobody in the book is set up as a desk in China, so there is nothing to send the dollars to. Add a user whose country is China and give them the China Desk abilities, then they appear here with a
    dollar and a yuan wallet of their own.
</div>
@endif

@if($sendForm)
<div class="bg-white rounded-lg shadow mb-8">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900">Send dollars to a desk in China</h2>
        <p class="text-sm text-gray-500 mt-1">Tick the transfers that have been paid for in Uganda, choose the desk, and the dollars go across in one movement. If the dollars are not in hand yet,
            buy them at the currency exchange first.</p>
    </div>
    <form method="POST" action="{{ route('china.send') }}">
        @csrf
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-10">
                            <input type="checkbox" data-check-all class="rounded border-gray-300" aria-label="Tick every transfer waiting to be sent">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receiver in China</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Dollars to send</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Receiver is handed</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Collected by</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($outbound as $transaction)
                    @php $heldForApproval = $transaction->hand_in_status === \App\Models\CashHandover::PENDING; @endphp
                    <tr>
                        <td class="px-4 py-3">
                            <input type="checkbox" name="transaction_ids[]" value="{{ $transaction->id }}" class="row-check rounded border-gray-300"
                                data-usd="{{ $transaction->total_usd_equivalent }}" data-pay="{{ $transaction->payoutAmount() ?: 0 }}"
                                data-pay-cur="{{ $transaction->payoutCurrency() }}"
                                @if($heldForApproval) disabled title="The cash for this transfer is named on hand-in {{ $transaction->cashHandover?->reference }}, which has not been approved yet. Approve that request first." @endif>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">
                            <a href="{{ route('transactions.show', $transaction) }}" class="text-green-800 hover:underline">{{ $transaction->receipt_number }}</a>
                            <span class="block text-xs {{ $heldForApproval ? 'text-amber-700' : 'text-gray-500' }}">
                                {{ $transaction->created_at->format('M d, Y') }}@if($heldForApproval) · waiting for hand-in approval @elseif($transaction->hand_in_status === \App\Models\CashHandover::APPROVED) · cash approved @endif
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-900">
                            {{ $transaction->receiver_name }}
                            <span class="block text-xs text-gray-500">for {{ $transaction->sender_name }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-900 tabular-nums">{{ \App\Models\Account::format('USD', $transaction->total_usd_equivalent) }}</td>
                        <td class="px-4 py-3 text-sm text-right text-gray-900 tabular-nums">{{ \App\Models\Account::format($transaction->payoutCurrency(), $transaction->payoutAmount()) }}</td>
                        <td class="px-6 py-3 text-sm text-gray-500">{{ $transaction->creator?->name ?? 'the office desk' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <div>
                <label for="recipient_id" class="block text-sm font-medium text-gray-700 mb-1">Send to desk in China</label>
                <select id="recipient_id" name="recipient_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    <option value="">Choose the person receiving in China</option>
                    @foreach($desks as $desk)
                    <option value="{{ $desk->id }}" @selected((int) old('recipient_id') === $desk->id)>{{ $desk->name }} ({{ \App\Models\Account::format('USD', \App\Models\Account::walletFor($desk, 'USD')->balance()) }} held)</option>
                    @endforeach
                </select>
                @error('recipient_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="source_account_id" class="block text-sm font-medium text-gray-700 mb-1">Pay from</label>
                <select id="source_account_id" name="source_account_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    <option value="">Choose which dollars</option>
                    @foreach($sources as $account)
                    <option value="{{ $account->id }}" @selected((int) old('source_account_id') === $account->id)>{{ $account->name }} ({{ \App\Models\Account::format('USD', $account->balance()) }})</option>
                    @endforeach
                </select>
                @error('source_account_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="send_date" class="block text-sm font-medium text-gray-700 mb-1">Date sent, optional</label>
                <input type="date" id="send_date" name="entry_date" value="{{ old('entry_date', now()->toDateString()) }}"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                @error('entry_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end">
                <button type="submit" class="bg-green-800 text-white px-5 py-2 rounded-md text-sm font-medium hover:bg-green-700 transition">Send to China</button>
            </div>
            <p class="md:col-span-4 text-sm text-gray-600">Ticked: <span data-sum="count" class="font-medium text-gray-900">0</span>
                transfer(s), <span data-sum="usd" data-currency="USD" class="font-medium text-gray-900 tabular-nums">$0.00</span> in dollars,
                <span data-payout-line data-empty="nothing due" class="font-medium text-gray-900">nothing due</span> on the other side.</p>
            {{-- The charge is the money this office kept from what it took in. It is never part of a
                 sending: the dollars that cross are only what the transfers are worth. --}}
            @if((float) $outboundCharges > 0)
            <p class="md:col-span-4 text-xs text-gray-500">Charges are not in this list's dollars. The
                {{ \App\Models\Account::format('UGX', $outboundCharges) }} these transfers charged stays in Uganda with whoever holds the cash.</p>
            @endif
            @if($outboundHeldBack > 0)
            <p class="md:col-span-4 text-xs text-amber-700">{{ number_format($outboundHeldBack) }}
                {{ \Illuminate\Support\Str::plural('transfer', $outboundHeldBack) }} here {{ $outboundHeldBack === 1 ? 'is' : 'are' }} named on a hand-in that
                has not been approved yet, so their boxes are locked. Approve the request on the Hand In Cash desk first.</p>
            @endif
            @error('transaction_ids') <p class="md:col-span-4 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    </form>
</div>
@elseif($canSend)
<div class="bg-white rounded-lg shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900">Nothing to send</h2>
    <p class="text-sm text-gray-600 mt-1">Every completed transfer you can see has already had its dollars sent to China. New bookings will appear here as they are collected.</p>
</div>
@endif

@if($payForm)
<div id="payouts" class="bg-white rounded-lg shadow mb-8 scroll-mt-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900">Pay receivers in China</h2>
        <p class="text-sm text-gray-500 mt-1">Tick a transfer once the receiver has been handed their money in China, whether that was yuan or dollars. The payment leaves the wallet, in that money, of the
            desk that was sent the dollars for it.</p>
    </div>
    <form method="POST" action="{{ route('china.payout') }}">
        @csrf
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-10">
                            <input type="checkbox" data-check-all class="rounded border-gray-300" aria-label="Tick every transfer waiting to be paid">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receiver</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">To hand over</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dollars arrived</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Booked by</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($inbound as $transaction)
                    <tr>
                        <td class="px-4 py-3">
                            <input type="checkbox" name="transaction_ids[]" value="{{ $transaction->id }}" class="row-check rounded border-gray-300"
                                data-usd="{{ $transaction->total_usd_equivalent }}" data-pay="{{ $transaction->payoutAmount() ?: 0 }}"
                                data-pay-cur="{{ $transaction->payoutCurrency() }}">
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">
                            <a href="{{ route('transactions.show', $transaction) }}" class="text-green-800 hover:underline">{{ $transaction->receipt_number }}</a>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-900">
                            {{ $transaction->receiver_name }}
                            <span class="block text-xs text-gray-500">{{ $transaction->receiver_contact ?: 'no contact given' }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-900 tabular-nums">{{ \App\Models\Account::format($transaction->payoutCurrency(), $transaction->payoutAmount()) }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">
                            {{ $transaction->settled_at?->format('M d, Y') ?? '-' }}
                            <span class="block text-xs text-gray-500">{{ $transaction->settled_at?->format('h:i A') }} with {{ $transaction->settledDesk?->name ?? 'the China desk' }}</span>
                        </td>
                        <td class="px-6 py-3 text-sm text-gray-500">{{ $transaction->creator?->name ?? 'the office desk' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <div>
                <label for="payout_date" class="block text-sm font-medium text-gray-700 mb-1">Date paid, optional</label>
                <input type="date" id="payout_date" name="entry_date" value="{{ old('entry_date', now()->toDateString()) }}"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                @error('entry_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end md:col-span-3">
                <button type="submit" class="bg-green-800 text-white px-5 py-2 rounded-md text-sm font-medium hover:bg-green-700 transition">Mark as paid</button>
            </div>
            <p class="md:col-span-4 text-sm text-gray-600">Ticked: <span data-sum="count" class="font-medium text-gray-900">0</span>
                transfer(s), <span data-payout-line data-empty="nothing to hand over" class="font-medium text-gray-900">nothing to hand over</span>.</p>
            @error('transaction_ids') <p class="md:col-span-4 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    </form>
</div>
@elseif($canPay)
<div id="payouts" class="bg-white rounded-lg shadow p-6 mb-8 scroll-mt-6">
    <h2 class="text-lg font-semibold text-gray-900">Nothing waiting to be paid</h2>
    <p class="text-sm text-gray-600 mt-1">Every transfer whose dollars reached China has been paid to its receiver.</p>
</div>
@endif

{{-- Every desk's two wallets and what the office owes them. The sending desk only ever needs the
     holding printed beside each name in the dropdown above, so the full table stays with the
     people who read every wallet, matching the Accounts screen. --}}
@if($deskBook)
<div class="bg-white rounded-lg shadow">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900">The desks in China</h2>
        <p class="text-sm text-gray-500 mt-1">Dollars arrive in each desk's own dollar wallet. A receiver paid in yuan is paid out of that desk's yuan wallet, and a receiver paid in dollars is paid
            straight out of the dollars that crossed. A yuan wallet below zero means that desk has paid receivers ahead of the dollars being changed into yuan, which the books allow on that
            side.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Desk</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Holding</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Dollar wallet</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Yuan wallet</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Still to hand over</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($desks as $desk)
                @php
                    $dollar = \App\Models\Account::walletFor($desk, 'USD');
                    $yuan = \App\Models\Account::walletFor($desk, 'RMB');
                    $owed = \App\Models\Transaction::sumPayouts(
                        \App\Models\Transaction::awaitingPayoutInChina()->where('settled_to', $desk->id)->get()
                    );
                @endphp
                <tr>
                    <td class="px-4 py-3 text-sm text-gray-900">{{ $desk->name }}<span class="block text-xs text-gray-500">{{ $desk->email }}</span></td>
                    <td class="px-4 py-3 text-sm text-gray-500">{{ $desk->getRoleNames()->implode(', ') ?: 'no role yet' }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900 tabular-nums">{{ \App\Models\Account::format('USD', $dollar->balance()) }}</td>
                    <td class="px-4 py-3 text-sm text-right {{ (float) $yuan->balance() < 0 ? 'text-red-700' : 'text-gray-900' }} tabular-nums">{{ \App\Models\Account::format('RMB', $yuan->balance()) }}</td>
                    <td class="px-6 py-3 text-sm text-right text-gray-900 tabular-nums">
                        @forelse($owed as $currency => $figure)
                        <span class="block">{{ \App\Models\Account::format($currency, $figure) }}</span>
                        @empty
                        <span class="text-gray-500">nothing owed</span>
                        @endforelse
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-8 text-center text-gray-500">No desk has been set up in China yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif

<script>
(function () {
    const forms = new Set();

    document.querySelectorAll('.row-check').forEach(function (box) {
        const form = box.closest('form');
        if (form) forms.add(form);
    });

    forms.forEach(function (form) {
        // A transfer still named on a hand-in that has not been approved cannot be sent, so its box
        // is drawn disabled and left out of the tally and out of "tick every row".
        const rows = Array.from(form.querySelectorAll('.row-check')).filter(function (row) { return !row.disabled; });
        const all = form.querySelector('[data-check-all]');
        const outs = Array.from(form.querySelectorAll('[data-sum]'));
        const phrases = Array.from(form.querySelectorAll('[data-payout-line]'));

        function money(currency, value) {
            const figure = value.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            return currency === 'USD' ? '$' + figure : '¥' + figure;
        }

        function refresh() {
            const sums = {count: 0, usd: 0};
            const payouts = {};

            rows.forEach(function (row) {
                if (!row.checked) return;
                sums.count++;
                sums.usd += parseFloat(row.dataset.usd || '0');
                const currency = row.dataset.payCur;
                payouts[currency] = (payouts[currency] || 0) + parseFloat(row.dataset.pay || '0');
            });

            outs.forEach(function (out) {
                const value = sums[out.dataset.sum] || 0;
                out.textContent = out.dataset.currency ? money(out.dataset.currency, value) : String(value);
            });

            // The money handed over is named per currency because a batch that pays one receiver in
            // yuan and another in dollars empties two wallets, and one total would describe neither.
            phrases.forEach(function (out) {
                const parts = [];
                if (payouts.RMB > 0) parts.push(money('RMB', payouts.RMB) + ' of yuan');
                if (payouts.USD > 0) parts.push(money('USD', payouts.USD) + ' in dollars');
                out.textContent = parts.length ? parts.join(' and ') : out.dataset.empty;
            });

            if (all) all.checked = rows.every(function (row) { return row.checked; });
        }

        if (all) all.addEventListener('change', function () {
            rows.forEach(function (row) { row.checked = all.checked; });
            refresh();
        });

        rows.forEach(function (row) { row.addEventListener('change', refresh); });
    });
})();
</script>
@endsection

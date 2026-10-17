@extends('layouts.app')

@section('title', 'My Wallet')

@section('content')
@php $isAgent = auth()->user()->hasRole('agent'); @endphp

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">My Wallet</h1>
    <p class="text-gray-600">Money you collected stays here until you hand it in. Commission is paid in shillings, whatever currency the transfer was booked in.</p>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
    @foreach($wallets as $currency => $account)
    @php
        $balance = (string) $account->balance();
        $kept = $commission[$account->id] ?? null;
    @endphp
    <div class="bg-white rounded-lg shadow p-6">
        <div class="text-sm text-gray-500 uppercase tracking-wide">{{ $currency }} Wallet</div>
        <div class="text-3xl font-bold text-gray-900 mt-2 tabular-nums">{{ \App\Models\Account::format($currency, $balance) }}</div>
        <dl class="mt-4 space-y-1 text-sm">
            <div class="flex justify-between gap-3">
                <dt class="text-gray-600">Handed in so far</dt>
                <dd class="text-gray-900 tabular-nums">{{ \App\Models\Account::format($currency, $handedIn[$account->id] ?? '0.00') }}</dd>
            </div>
            @if($kept !== null)
            <div class="flex justify-between gap-3">
                <dt class="text-gray-600">Commission kept</dt>
                <dd class="text-gray-900 tabular-nums">{{ \App\Models\Account::format('UGX', $kept) }}</dd>
            </div>
            @endif
        </dl>
        <div class="text-xs text-gray-400 mt-3">Account: {{ $account->code }}</div>
    </div>
    @endforeach
</div>

@if($isAgent)
@php
    $chosen = old('currency', array_key_first($wallets) ?? '');
    $heldNow = (float) (($wallets[$chosen] ?? null)?->balance() ?? 0);
    // Handing in the whole bag is the usual case, so the box opens at what the chosen
    // wallet holds. A figure typed before a failed submit always wins over the guess,
    // and an empty wallet leaves the box empty rather than opening at a zero it cannot send.
    $prefill = old('amount', $heldNow > 0 ? (string) round($heldNow, 2) : '');
@endphp
<div class="bg-white rounded-lg shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900">Hand cash in to the office</h2>
    <p class="text-sm text-gray-600 mt-1 mb-4">Enter what you are carrying in. The office till gains exactly that figure. The amount opens at your whole balance; change it if you are handing in part of it.</p>
    <form method="POST" action="{{ route('wallets.hand-in') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
        @csrf
        <div>
            <label for="hand-in-currency" class="block text-sm font-medium text-gray-700 mb-1">Money</label>
            <select id="hand-in-currency" name="currency" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                @foreach($wallets as $currency => $account)
                <option value="{{ $currency }}" data-balance="{{ (string) round((float) $account->balance(), 2) }}" @selected($chosen === $currency)>{{ $currency }} ({{ \App\Models\Account::format($currency, $account->balance()) }} in hand)</option>
                @endforeach
            </select>
            @error('currency') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="hand-in-amount" class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
            <input type="number" id="hand-in-amount" name="amount" step="0.01" min="0.01" required value="{{ $prefill }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
            @error('amount') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="md:col-span-2">
            <label for="hand-in-note" class="block text-sm font-medium text-gray-700 mb-1">Note, optional</label>
            <input type="text" id="hand-in-note" name="note" maxlength="255" value="{{ old('note') }}" placeholder="e.g. Full day's collections"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
            @error('note') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="md:col-span-4 flex justify-end">
            <button type="submit" class="bg-green-800 text-white px-5 py-2 rounded-md text-sm font-medium hover:bg-green-700 transition">Hand in cash</button>
        </div>
    </form>
</div>
@endif

<div class="bg-white rounded-lg shadow">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900">Cash history</h2>
        <p class="text-sm text-gray-500 mt-1">Every movement in your wallets, newest first. The last figure on a line is what was left in your hand after it.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Wallet</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">What happened</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">In</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Out</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Left in hand</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($movements as $line)
                <tr>
                    <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $line['date']->format('M d, Y') }}</td>
                    <td class="px-4 py-3 text-sm text-gray-500">{{ $line['currency'] }}</td>
                    <td class="px-4 py-3 text-sm text-gray-900">
                        {{ $line['label'] }}
                        <span class="block text-xs text-gray-500">{{ $line['description'] }}</span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-500">{{ $line['reference'] ?? '-' }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900 tabular-nums">{{ $line['in'] !== null ? \App\Models\Account::format($line['currency'], $line['in']) : '-' }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900 tabular-nums">{{ $line['out'] !== null ? \App\Models\Account::format($line['currency'], $line['out']) : '-' }}</td>
                    <td class="px-6 py-3 text-sm text-right text-gray-900 tabular-nums">{{ \App\Models\Account::format($line['currency'], $line['running']) }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-6 py-8 text-center text-gray-500">Nothing has moved through your wallets yet. Book a transfer and the cash you collect shows up here.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        var money = document.getElementById('hand-in-currency');
        var amount = document.getElementById('hand-in-amount');

        if (!money || !amount) { return; }

        var held = function () { return money.options[money.selectedIndex].dataset.balance; };

        // The amount follows the wallet it names until the person takes over the typing.
        // Once they have moved the figure off the balance, switching wallets leaves their
        // figure alone, because a part payment they typed is not a guess to overwrite.
        // An empty wallet clears the box rather than typing a zero it cannot send.
        money.dataset.held = held();
        money.addEventListener('change', function () {
            if (amount.value === '' || amount.value === money.dataset.held) {
                amount.value = held() === '0' ? '' : held();
            }
            money.dataset.held = held();
        });
    })();
</script>
@endpush

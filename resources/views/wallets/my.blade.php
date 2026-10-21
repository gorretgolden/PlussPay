@extends('layouts.app')

@section('title', 'My Wallet')

@section('content')
@php
    $isSuperAgent = auth()->user()->hasRole('super-agent');
    $isAgent = auth()->user()->hasRole('agent') && ! $isSuperAgent;
    $isChinaDesk = (auth()->user()->country ?: \App\Models\User::DEFAULT_COUNTRY) === 'CN';
    $handInHere = $isAgent && ! $isChinaDesk;
@endphp

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">My Wallet</h1>
    <p class="text-gray-600">
        @if($isSuperAgent)
            Cash handed to you by the agents stays here until you send it to the China desk. Commission is paid in shillings, whatever currency the transfer was booked in.
        @elseif($isChinaDesk)
            Money sent from Uganda arrives here, and the yuan or dollars you hand a receiver leave from here. Commission is paid in shillings, whatever currency the transfer was booked in.
        @else
            Money you collected stays here until the super agent takes it in hand. Commission is paid in shillings, whatever currency the transfer was booked in.
        @endif
    </p>
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
        <x-wallet-updated :at="$account->lastMovementAt()" class="mt-2" />
        <dl class="mt-4 space-y-1 text-sm">
            @if($isSuperAgent)
            <div class="flex justify-between gap-3">
                <dt class="text-gray-600">Received from agents</dt>
                <dd class="text-gray-900 tabular-nums">{{ \App\Models\Account::format($currency, $received[$currency] ?? '0.00') }}</dd>
            </div>
            @elseif(! $isChinaDesk)
            <div class="flex justify-between gap-3">
                <dt class="text-gray-600">Handed in so far</dt>
                <dd class="text-gray-900 tabular-nums">{{ \App\Models\Account::format($currency, $handedIn[$account->id] ?? '0.00') }}</dd>
            </div>
            @endif
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

@if($handInHere)
@php
    $chosen = old('currency', array_key_first($wallets) ?? '');
    $heldNow = (float) (($wallets[$chosen] ?? null)?->balance() ?? 0);
    // Handing in the whole bag is the usual case, so the box opens at what the chosen
    // wallet holds. A figure typed before a failed submit always wins over the guess,
    // and an empty wallet leaves the box empty rather than opening at a zero it cannot send.
    $prefill = old('amount', $heldNow > 0 ? (string) round($heldNow, 2) : '');
@endphp
<div id="hand-in" class="bg-white rounded-lg shadow p-6 mb-8 scroll-mt-20">
    <h2 class="text-lg font-semibold text-gray-900">Hand cash in to your super agent</h2>
    @if($superAgents->isEmpty())
        <p class="text-sm text-gray-600 mt-2">No super agent is active yet. Ask the office to set one up before you hand any cash in.</p>
    @else
    <p class="text-sm text-gray-600 mt-1 mb-4">The money stays in your wallet until the super agent approves it. You will see the decision here, and the cash only changes hands when they approve.</p>
    <form method="POST" action="{{ route('cash-handovers.store') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
        @csrf
        <div>
            <label for="hand-in-super-agent" class="block text-sm font-medium text-gray-700 mb-1">Super agent</label>
            <select id="hand-in-super-agent" name="super_agent_id" required @if($errors->has('super_agent_id')) aria-invalid="true" @endif class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500{{ $errors->has('super_agent_id') ? ' field-invalid' : '' }}">
                @foreach($superAgents as $superAgent)
                <option value="{{ $superAgent->id }}" @selected((string) old('super_agent_id') === (string) $superAgent->id)>{{ $superAgent->name }}</option>
                @endforeach
            </select>
            @error('super_agent_id') <p class="field-error text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="hand-in-currency" class="block text-sm font-medium text-gray-700 mb-1">Wallet</label>
            <select id="hand-in-currency" name="currency" required @if($errors->has('currency')) aria-invalid="true" @endif class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500{{ $errors->has('currency') ? ' field-invalid' : '' }}">
                @foreach($wallets as $currency => $account)
                <option value="{{ $currency }}" data-balance="{{ (string) round((float) $account->balance(), 2) }}" @selected($chosen === $currency)>{{ $currency }} ({{ \App\Models\Account::format($currency, $account->balance()) }} in hand)</option>
                @endforeach
            </select>
            @error('currency') <p class="field-error text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="hand-in-amount" class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
            <input type="number" id="hand-in-amount" name="amount" step="0.01" min="0.01" max="{{ $heldNow > 0 ? $heldNow : '' }}" required value="{{ $prefill }}"
                aria-describedby="hand-in-amount-hint" @if($errors->has('amount')) aria-invalid="true" @endif
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500{{ $errors->has('amount') ? ' field-invalid' : '' }}">
            <p id="hand-in-amount-hint" class="text-xs text-gray-400 mt-1">Cannot be more than the {{ $chosen }} wallet holds right now.</p>
            @error('amount') <p class="field-error text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="md:col-span-3">
            <label for="hand-in-note" class="block text-sm font-medium text-gray-700 mb-1">Note, optional</label>
            <input type="text" id="hand-in-note" name="note" maxlength="255" value="{{ old('note') }}" placeholder="e.g. Full day's collections"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500{{ $errors->has('note') ? ' field-invalid' : '' }}">
            @error('note') <p class="field-error text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="md:col-span-1 flex md:justify-end">
            <button type="submit" class="w-full md:w-auto bg-green-800 text-white px-5 py-2 rounded-md text-sm font-medium hover:bg-green-700 transition">Send for approval</button>
        </div>
    </form>
    @endif
</div>
@endif

@if(($isAgent || $isSuperAgent) && $handovers->isNotEmpty())
<div class="bg-white rounded-lg shadow mb-8 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900">Cash hand-in requests</h2>
        <p class="text-sm text-gray-500 mt-1">A request moves no money until it is approved. A decline leaves the cash in the agent's wallet.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-[720px] divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Requested</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ $isSuperAgent ? 'From agent' : 'To super agent' }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Note</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach($handovers as $handover)
                <tr>
                    <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $handover->created_at->format('M d, Y') }}<span class="text-gray-500">, {{ $handover->created_at->format('h:i A') }}</span></td>
                    <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $handover->reference }}</td>
                    <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $isSuperAgent ? $handover->agent?->name : $handover->superAgent?->name }}</td>
                    <td class="px-4 py-3 text-sm text-gray-900 tabular-nums whitespace-nowrap">{{ $handover->figure() }}</td>
                    <td class="px-4 py-3 text-sm whitespace-nowrap">
                        @if($handover->status === \App\Models\CashHandover::APPROVED)
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Approved</span>
                        @elseif($handover->status === \App\Models\CashHandover::DECLINED)
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">Declined</span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Pending</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600">
                        @if($handover->decision_note)
                            {{ $handover->decision_note }}
                        @elseif($handover->note)
                            {{ $handover->note }}
                        @endif
                        @if($isSuperAgent && $handover->isPending())
                        <div class="flex flex-wrap gap-2 mt-2">
                            <form method="POST" action="{{ route('cash-handovers.approve', $handover) }}">
                                @csrf
                                <button type="submit" class="bg-green-800 text-white px-3 py-1.5 rounded-md text-xs font-medium hover:bg-green-700">Approve</button>
                            </form>
                            <form method="POST" action="{{ route('cash-handovers.decline', $handover) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                <input type="text" name="decision_note" required maxlength="255" placeholder="Why it is going back"
                                    aria-label="Reason for declining {{ $handover->reference }}"
                                    class="px-2 py-1.5 border border-gray-300 rounded-md text-xs w-44 focus:ring-2 focus:ring-green-500 focus:border-green-500">
                                <button type="submit" class="bg-white text-red-700 border border-red-300 px-3 py-1.5 rounded-md text-xs font-medium hover:bg-red-50">Decline</button>
                            </form>
                        </div>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@php
    $anyFilter = request()->filled('search') || request()->filled('wallet') || request()->filled('kind')
        || request()->filled('direction') || request()->filled('from') || request()->filled('to');
@endphp
<div class="bg-white rounded-lg shadow">
    <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap justify-between items-start gap-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Cash history</h2>
            <p class="text-sm text-gray-500 mt-1">Every movement in your wallets, newest first, with the balance it left behind. The time is the moment the line was written.</p>
        </div>
        <a href="{{ route('export.cash-history', request()->query()) }}" class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600 whitespace-nowrap">
            Export Excel @if($anyFilter)(this filter) @endif
        </a>
    </div>

    <div class="px-6 py-4 border-b border-gray-200">
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[12rem]">
                <label class="block text-xs text-gray-500 mb-1">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Receipt number, note or what happened"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Wallet</label>
                <select name="wallet" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">Any wallet</option>
                    @foreach($wallets as $currency => $account)
                        <option value="{{ $currency }}" @selected(request('wallet') === $currency)>{{ $currency }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">What happened</label>
                <select name="kind" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">Anything</option>
                    @foreach($kinds as $type => $label)
                        <option value="{{ $type }}" @selected(request('kind') === $type)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Money going</label>
                <select name="direction" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">In and out</option>
                    <option value="in" @selected(request('direction') === 'in')>Coming in</option>
                    <option value="out" @selected(request('direction') === 'out')>Going out</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">From</label>
                <input type="date" name="from" value="{{ request('from') }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">To</label>
                <input type="date" name="to" value="{{ request('to') }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
            <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
            <a href="{{ route('wallet.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear</a>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-[880px] divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date and time</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Wallet</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">What happened</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Transfer</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">In</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Out</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Left in hand</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($movements as $line)
                <tr>
                    <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $line['date']->format('M d, Y') }}<span class="text-gray-500">, {{ $line['clock']?->format('h:i A') }}</span></td>
                    <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $line['currency'] }}</td>
                    <td class="px-4 py-3 text-sm text-gray-900">
                        {{ $line['label'] }}
                        <span class="block text-xs text-gray-500">{{ $line['description'] }}</span>
                    </td>
                    <td class="px-4 py-3 text-sm whitespace-nowrap">
                        @if($line['transaction_id'] && (auth()->id() === $line['booked_by'] || auth()->user()->can('view-all-transactions')))
                            <a href="/transactions/{{ $line['transaction_id'] }}" class="text-green-800 hover:underline">{{ $line['reference'] ?? '-' }}</a>
                        @else
                            <span class="text-gray-500">{{ $line['reference'] ?? '-' }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm whitespace-nowrap">
                        @if($line['status'])
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $line['status'] === 'completed' ? 'bg-green-100 text-green-800' : ($line['status'] === 'cancelled' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-800') }}">{{ ucfirst($line['status']) }}</span>
                        @else
                            <span class="text-gray-400">-</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-left text-gray-900 tabular-nums whitespace-nowrap">{{ $line['in'] !== null ? \App\Models\Account::format($line['currency'], $line['in']) : '-' }}</td>
                    <td class="px-4 py-3 text-sm text-left text-gray-900 tabular-nums whitespace-nowrap">{{ $line['out'] !== null ? \App\Models\Account::format($line['currency'], $line['out']) : '-' }}</td>
                    <td class="px-4 py-3 text-sm text-left text-gray-900 tabular-nums whitespace-nowrap">{{ \App\Models\Account::format($line['currency'], $line['running']) }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-6 py-8 text-center text-gray-500">
                        {{ $anyFilter
                            ? 'No movement of your wallets fits that filter. Clear it to see the whole book.'
                            : 'Nothing has moved through your wallets yet. Book a transfer and the cash you collect shows up here.' }}
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($movements->hasPages())
        <div class="border-t border-gray-200 px-4 py-4">{{ $movements->links() }}</div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function () {
        var money = document.getElementById('hand-in-currency');
        var amount = document.getElementById('hand-in-amount');
        var hint = document.getElementById('hand-in-amount-hint');

        if (!money || !amount) { return; }

        var held = function () { return money.options[money.selectedIndex].dataset.balance; };

        // The amount follows the wallet it names until the person takes over the typing.
        // Once they have moved the figure off the balance, switching wallets leaves their
        // figure alone, because a part payment they typed is not a guess to overwrite.
        // An empty wallet clears the box rather than typing a zero it cannot send.
        // The ceiling travels with the wallet too, so a figure bigger than the drawer is
        // caught at the box instead of after the button.
        money.dataset.held = held();
        amount.max = held();
        money.addEventListener('change', function () {
            if (amount.value === '' || amount.value === money.dataset.held) {
                amount.value = held() === '0' ? '' : held();
            }
            money.dataset.held = held();
            amount.max = held();
            if (hint) { hint.textContent = 'Cannot be more than the ' + money.value + ' wallet holds right now.'; }
        });
    })();
</script>
@endpush

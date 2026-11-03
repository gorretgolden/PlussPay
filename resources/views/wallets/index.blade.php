@extends('layouts.app')

@section('title', 'User Accounts')

@section('content')
<div class="mb-6 flex flex-wrap justify-between items-center gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">User Accounts</h1>
        <p class="text-gray-600">Cash each person is holding. A dash means that desk doesn't use the currency.</p>
    </div>
    @can('adjust-wallets')
    <button type="button" data-open-adjust-modal class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Adjust Wallet</button>
    @endcan
</div>

@can('adjust-wallets')
<div id="adjust-wallet-modal" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="adjust-wallet-modal-title">
    <div class="fixed inset-0 bg-black bg-opacity-50" data-close-modal></div>
    <div class="fixed inset-0 flex items-start justify-center overflow-y-auto p-4 sm:p-6">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl my-8 relative">
            <div class="flex items-start justify-between p-6 border-b">
                <div>
                    <h2 id="adjust-wallet-modal-title" class="text-xl font-bold text-gray-900">Adjust an Agent's Wallet</h2>
                    <p class="text-sm text-gray-500 mt-1">Credit or debit cash held by an agent.</p>
                </div>
                <button type="button" data-close-modal aria-label="Close" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-6">
                <form id="adjust-wallet-form" method="POST" action="{{ route('wallets.adjust') }}" data-lock-on-submit>
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="md:col-span-2">
                            <label for="agent_id" class="block text-sm font-medium text-gray-700 mb-1">Agent</label>
                            <select id="agent_id" name="agent_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                                <option value="">Choose an agent</option>
                                @foreach($adjustable as $agent)
                                    <option value="{{ $agent->id }}" @selected(old('agent_id') == $agent->id)>{{ $agent->name }}</option>
                                @endforeach
                            </select>
                            @error('agent_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="direction" class="block text-sm font-medium text-gray-700 mb-1">Action</label>
                            <select id="direction" name="direction" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                                <option value="credit" @selected(old('direction', 'credit') === 'credit')>Credit (add)</option>
                                <option value="debit" @selected(old('direction') === 'debit')>Debit (remove)</option>
                            </select>
                        </div>
                        <div>
                            <label for="amount" class="block text-sm font-medium text-gray-700 mb-1">Amount (UGX)</label>
                            <input id="amount" type="text" inputmode="numeric" name="amount" step="0.01" min="0.01" required value="{{ old('amount') }}"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                            @error('amount') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Reason</label>
                            <input id="description" type="text" name="description" required maxlength="255" value="{{ old('description') }}"
                                placeholder="e.g. Advance payment, correction for PS-..."
                                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                            @error('description') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </form>
            </div>
            <div class="sticky bottom-0 bg-white border-t p-6 flex gap-3">
                <button type="submit" form="adjust-wallet-form" class="bg-green-800 text-white px-5 py-2 rounded-md text-sm font-medium hover:bg-green-700">Post Adjustment</button>
                <button type="button" data-close-modal class="border border-gray-300 text-gray-700 px-5 py-2 rounded-md text-sm">Cancel</button>
            </div>
        </div>
    </div>
</div>
@endcan

@if(empty($allWallets))
<div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">No user accounts yet.</div>
@else
<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Person</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Country</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">UGX in hand</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">USD in hand</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">RMB in hand</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @foreach($allWallets as $agentId => $data)
            @php $held = $data['wallets']; @endphp
            <tr>
                <td class="px-6 py-4 text-sm font-medium text-gray-900">
                    @can('view-accounts')
                        <a href="{{ route('accounts.movements', ['owner_id' => $agentId]) }}" class="text-green-800 hover:underline">{{ $data['user']->name }}</a>
                    @else
                        {{ $data['user']->name }}
                    @endcan
                    @unless($data['user']->is_active)
                        <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-red-50 text-red-700">Blocked</span>
                    @endunless
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">{{ $data['user']->countryLabel() ?: 'Not set' }}</td>
                @foreach(['UGX', 'USD', 'RMB'] as $currency)
                @php $given = $data['handed_in'][$currency] ?? '0.00'; @endphp
                <td class="px-6 py-4 text-sm text-left text-gray-900">
                    @if($held[$currency] ?? null)
                        @can('view-accounts')
                            <a href="{{ route('accounts.show', $held[$currency]) }}" class="hover:underline">{{ \App\Models\Account::format($currency, $held[$currency]->balance()) }}</a>
                        @else
                            {{ \App\Models\Account::format($currency, $held[$currency]->balance()) }}
                        @endcan
                        @if(bccomp((string) $given, '0', 2) > 0)
                        <span class="block text-xs text-gray-500">handed in {{ \App\Models\Account::format($currency, $given) }}</span>
                        @endif
                    @else
                        <span class="text-gray-400">&mdash;</span>
                    @endif
                </td>
                @endforeach
            </tr>
            @endforeach
        </tbody>
        <tfoot class="bg-gray-50">
            <tr>
                <td class="px-6 py-3 text-sm font-semibold text-gray-900" colspan="2">Total</td>
                @foreach(['UGX', 'USD', 'RMB'] as $currency)
                <td class="px-6 py-3 text-sm text-left font-semibold text-gray-900">{{ \App\Models\Account::format($currency, $totals[$currency] ?? '0.00') }}</td>
                @endforeach
            </tr>
        </tfoot>
    </table>
</div>
@endif
@endsection
@push('scripts')
<script>
(() => {
    'use strict';
    const modal = document.getElementById('adjust-wallet-modal');
    if (!modal) return;
    const form = document.getElementById('adjust-wallet-form');
    const openButton = document.querySelector('[data-open-adjust-modal]');
    const closeButtons = modal.querySelectorAll('[data-close-modal]');
    let previousFocus = null;
    let dirty = false;

    function open() {
        previousFocus = document.activeElement;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        const first = form.querySelector('select,input,textarea');
        if (first) setTimeout(() => first.focus(), 0);
    }

    function close() {
        if (dirty && !confirm('Discard unsaved changes?')) return;
        modal.hidden = true;
        document.body.style.overflow = '';
        form.reset();
        dirty = false;
        if (previousFocus) previousFocus.focus();
    }

    openButton?.addEventListener('click', open);
    closeButtons.forEach(btn => btn.addEventListener('click', close));

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !modal.hidden) { e.preventDefault(); close(); }
    });

    const focusableSel = 'select:not([disabled]),input:not([disabled]):not([type=hidden]),textarea:not([disabled]),button:not([disabled])';
    modal.addEventListener('keydown', e => {
        if (e.key !== 'Tab') return;
        const els = Array.from(modal.querySelectorAll(focusableSel));
        if (!els.length) return;
        const first = els[0], last = els[els.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    form?.addEventListener('input', () => { dirty = true; }, true);
    form?.addEventListener('change', () => { dirty = true; }, true);
    form?.addEventListener('submit', () => { dirty = false; }, true);

    form?.addEventListener('submit', () => {
        form.querySelectorAll('select,input,textarea').forEach(el => { el.disabled = true; });
        const btn = document.querySelector('button[form="adjust-wallet-form"]');
        if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
    });

    // Re-open the modal when validation errors come back from the server.
    @if($errors->any() && old('agent_id') !== null)
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    @endif
})();
</script>
@endpush

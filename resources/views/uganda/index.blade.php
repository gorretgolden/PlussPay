@extends('layouts.app')

@section('title', 'Uganda Desk')

@section('content')
@php
    $payForm = $inbound->isNotEmpty();
@endphp

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Uganda Desk</h1>
    <p class="text-gray-600 text-sm max-w-3xl">A transfer booked in China is finished when the receiver here is handed their shillings. There is no crossing to record first: the booking arrives with
        its money in Uganda, so every transfer to Uganda sits in this queue from the day it is taken until it is paid.</p>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Waiting to be paid</p>
        @include('china.partials.payout-figures', ['payouts' => $inboundPayouts])
        <p class="text-xs text-gray-500 mt-1">{{ number_format($inbound->count()) }} transfer{{ $inbound->count() === 1 ? '' : 's' }} waiting to be handed over</p>
    </div>
</div>

@if($payForm)
<div id="payouts" class="bg-white rounded-lg shadow mb-8 scroll-mt-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900">Pay receivers in Uganda</h2>
        <p class="text-sm text-gray-500 mt-1">Tick a transfer once the receiver has been handed their shillings here. The payment leaves the office shilling book.</p>
    </div>
    <form method="POST" action="{{ route('uganda.payout') }}">
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
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Taken in</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Booked by</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($inbound as $transaction)
                    <tr>
                        <td class="px-4 py-3">
                            <input type="checkbox" name="transaction_ids[]" value="{{ $transaction->id }}" class="row-check rounded border-gray-300"
                                data-pay="{{ $transaction->payoutAmount() ?: 0 }}">
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">
                            <a href="{{ route('transactions.show', $transaction) }}" class="text-green-800 hover:underline">{{ $transaction->receipt_number }}</a>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-900">
                            {{ $transaction->receiver_name }}
                            <span class="block text-xs text-gray-500">{{ $transaction->receiver_contact ?: 'no contact given' }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-900 tabular-nums">{{ \App\Models\Account::format('UGX', $transaction->payoutAmount()) }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">
                            {{ $transaction->created_at->format('M d, Y') }}
                            <span class="block text-xs text-gray-500">{{ $transaction->created_at->format('h:i A') }} in {{ $transaction->originLabel() }}</span>
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
@else
<div id="payouts" class="bg-white rounded-lg shadow p-6 mb-8 scroll-mt-6">
    <h2 class="text-lg font-semibold text-gray-900">Nothing waiting to be paid</h2>
    <p class="text-sm text-gray-600 mt-1">Every transfer booked to Uganda has been paid to its receiver.</p>
</div>
@endif

@push('scripts')
<script>
(function () {
    const form = document.querySelector('#payouts form');
    if (!form) return;

    const rows = Array.from(form.querySelectorAll('.row-check'));
    const all = form.querySelector('[data-check-all]');
    const outs = Array.from(form.querySelectorAll('[data-sum]'));
    const phrase = form.querySelector('[data-payout-line]');

    function refresh() {
        let count = 0;
        let shillings = 0;

        rows.forEach(function (row) {
            if (!row.checked) return;
            count++;
            shillings += parseFloat(row.dataset.pay || '0');
        });

        outs.forEach(function (out) {
            const value = out.dataset.sum === 'count' ? count : 0;
            out.textContent = String(value);
        });

        phrase.textContent = shillings > 0 ? 'UGX ' + shillings.toLocaleString('en-US') + ' to hand over' : phrase.dataset.empty;

        if (all) all.checked = rows.every(function (row) { return row.checked; });
    }

    if (all) all.addEventListener('change', function () {
        rows.forEach(function (row) { row.checked = all.checked; });
        refresh();
    });

    rows.forEach(function (row) { row.addEventListener('change', refresh); });
})();
</script>
@endpush
@endsection

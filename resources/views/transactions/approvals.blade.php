@extends('layouts.app')

@section('title', 'Approvals')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Approvals</h1>
        <p class="text-sm text-gray-600 mt-1">
            {{ number_format($totalPending) }} {{ \Illuminate\Support\Str::plural('transfer', $totalPending) }} booked and not yet approved. Approving posts the money into the ledger.
        </p>
    </div>
    <a href="{{ route('transactions.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">All Transactions</a>
</div>

@if($pending->isEmpty())
<div class="bg-white rounded-lg shadow p-10 text-center">
    <p class="text-gray-600">Nothing is waiting. Every booked transfer has been approved or cancelled.</p>
</div>
@else
<form method="POST" action="{{ route('transactions.approve') }}">
    @csrf

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" onclick="toggleAllPending(this)" class="rounded border-gray-300">
                Select all on this page
            </label>
            <button type="submit" class="bg-green-800 text-white px-5 py-2 rounded-md text-sm hover:bg-green-700">
                Approve selected
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3"></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt #</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sender</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receiver</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Charge</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Customer Pays</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Booked By</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Booked At</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($pending as $t)
                    <tr>
                        <td class="px-4 py-4">
                            <input type="checkbox" name="ids[]" value="{{ $t->id }}" class="rounded border-gray-300 pending-check">
                        </td>
                        <td class="px-6 py-4 text-sm font-medium text-green-800">
                            <a href="{{ route('receipts.show', $t->receipt_number) }}">{{ $t->receipt_number }}</a>
                        </td>
                        <td class="px-4 py-4 text-sm text-gray-900">{{ $t->sender_name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900">{{ $t->receiver_name }}</td>
                        <td class="px-4 py-4 text-sm text-gray-900 text-right">{{ $t->principalLabel() }}</td>
                        <td class="px-4 py-4 text-sm text-gray-900 text-right">{{ $t->feeLabel() }}</td>
                        <td class="px-4 py-4 text-sm font-medium text-gray-900 text-right">{{ $t->collectedAmount() }}</td>
                        <td class="px-4 py-4 text-sm text-gray-700">{{ $t->creator?->name ?? '-' }}</td>
                        <td class="px-4 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $t->created_at->format('M d, Y h:i A') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        <p class="text-xs text-gray-500">Tick the rows to sign off, then use Approve selected. A transfer you will not approve is cancelled from the Transactions list, where a reason is asked for.</p>
    </div>
</form>

<div class="mt-4">
    {{ $pending->links() }}
</div>

<script>
function toggleAllPending(source) {
    document.querySelectorAll('.pending-check').forEach(box => box.checked = source.checked);
}
</script>
@endif
@endsection

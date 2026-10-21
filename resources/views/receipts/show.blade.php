@extends('layouts.app')

@section('title', 'Receipt ' . $transaction->receipt_number)

@push('styles')
@include('receipts.partials.slip-styles')
<style>
    .receipt-stage {
        max-width: 162mm;
        margin: 0 auto;
        padding: clamp(10px, 2.5vw, 26px);
        border-radius: 12px;
        background: #e9e6dc;
    }
    @media (max-width: 760px) {
        .receipt-stage { padding: 8px; border-radius: 8px; }
    }
    @media print {
        body { background: #fff !important; }
        .app-shell { margin-left: 0 !important; width: 100% !important; }
        main { padding: 0 !important; }
        .receipt-stage { padding: 0 !important; border-radius: 0 !important; background: #fff !important; }
        .slip { max-width: none !important; padding: 0 !important; border: 0 !important; box-shadow: none !important; }
        @page { size: A5 portrait; margin: 6mm; }
    }
</style>
@endpush

@section('content')
<div class="mb-6 flex flex-wrap justify-between items-center gap-3 no-print">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Receipt: {{ $transaction->receipt_number }}</h1>
        <p class="text-gray-600">Send money to {{ $transaction->destinationLabel() }}</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('receipts.print', $transaction->receipt_number) }}" target="_blank" rel="noopener"
            class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Print Receipt</a>
        <a href="{{ route('transactions.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Back</a>
    </div>
</div>

<div class="receipt-stage">
    @include('receipts.partials.slip')
</div>
@endsection

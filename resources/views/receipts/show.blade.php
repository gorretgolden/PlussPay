@extends('layouts.app')

@section('title', 'Receipt ' . $transaction->receipt_number)

@section('content')
@php
    $companyName = \App\Models\Setting::get('company_name', 'Plus Save Financial Solutions');
    $companyPhone = \App\Models\Setting::get('company_phone');
    $companyEmail = \App\Models\Setting::get('company_email');
    $companyAddress = \App\Models\Setting::get('company_address');
    $companyWebsite = \App\Models\Setting::get('company_website');
    // PlusPay leads the header and the company that signs it holds the right corner. The artwork is
    // read from public/plussave-logo.png, so a rebrand is a file swap and not a code change.
    $companyLogo = is_file(public_path('plussave-logo.png'));
    $usdRate = $transaction->shillingRateCell();
    $rmbRate = $transaction->yuanRateCell();
    // Whoever the booking was written into is who took the money: the clerk signed in at the
    // counter, or the agent the office booked for. That name is saved on the transfer, so a receipt
    // always carries the person standing there when the cash changed hands.
    $issuedBy = $transaction->creator?->name ?: 'The office';
@endphp

@push('styles')
<style>
    @media print {
        body { background: #fff !important; }
        .app-shell { margin-left: 0 !important; width: 100% !important; }
        main { padding: 0 !important; }
        .receipt-card { box-shadow: none !important; max-width: none !important; padding: 0 !important; }
        .receipt-line { break-inside: avoid; }
        @page { size: A4; margin: 14mm; }
    }
</style>
@endpush

<div class="mb-6 flex justify-between items-center no-print">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Receipt: {{ $transaction->receipt_number }}</h1>
        <p class="text-gray-600">Send Money to China</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('receipts.print', $transaction->receipt_number) }}" target="_blank"
            class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Print Receipt</a>
        <a href="{{ route('transactions.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Back</a>
    </div>
</div>

<div class="bg-white rounded-lg shadow p-4 sm:p-8 max-w-3xl mx-auto receipt-card">
    {{-- Header --}}
    <div class="mb-6 border-b pb-4">
        <div class="flex items-start justify-between gap-4">
            <img src="{{ asset('logo.webp') }}" alt="PlusPay" width="640" height="232" class="h-10 w-auto sm:h-12">
            @if($companyLogo)
                {{-- The artwork is a square badge, so it is drawn taller than the wide PlusPay mark to
                     keep the words inside it readable. --}}
                <img src="{{ asset('plussave-logo.png') }}" alt="{{ $companyName }}" width="447" height="447" class="h-14 w-auto sm:h-16">
            @endif
        </div>
        <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
            <span class="bg-green-800 text-white px-4 py-1 rounded text-sm font-bold">RECEIPT</span>
            <span class="inline-flex items-center gap-1 px-3 py-1 text-sm rounded-full font-medium
                @if($transaction->status === 'completed') bg-green-100 text-green-800
                @elseif($transaction->status === 'pending') bg-yellow-100 text-yellow-800
                @else bg-red-100 text-red-800 @endif">
                @if($transaction->status === 'completed')
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                @endif
                {{ ucfirst($transaction->status) }}
            </span>
        </div>
        <p class="mt-2 text-right text-lg font-bold leading-tight text-gray-900 whitespace-nowrap">{{ $transaction->receipt_number }}</p>
        <p class="text-right text-sm text-gray-600 whitespace-nowrap">{{ $transaction->created_at->format('M d, Y h:i A') }}</p>
    </div>

    @if($transaction->status !== 'completed')
    {{-- A booking normally completes as it is entered, so this only appears for the odd
         transfer left Pending and for cancellations, where the money must not move. --}}
    <div class="mb-6 rounded-md border px-4 py-3 text-sm font-medium
        @if($transaction->status === 'pending') bg-yellow-50 border-yellow-300 text-yellow-800
        @else bg-red-50 border-red-300 text-red-800 @endif">
        @if($transaction->status === 'pending')
            Awaiting approval. The money is not released until an admin approves this transfer.
        @else
            Cancelled. Do not pay out against this receipt.
        @endif
    </div>
    @endif

    {{-- Sender / Receiver --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 mb-4">
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Sender</p>
            <p class="text-sm font-medium text-gray-900">{{ $transaction->sender_name }}</p>
            @if($transaction->sender_contact)
                <p class="text-sm text-gray-600">{{ $transaction->sender_contact }}</p>
            @endif
        </div>
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">Receiver, in China</p>
            <p class="text-sm font-medium text-gray-900">{{ $transaction->receiver_name }}</p>
            @if($transaction->receiver_contact)
                <p class="text-sm text-gray-600">{{ $transaction->receiver_contact }}</p>
            @endif
        </div>
    </div>

    {{-- Whose hand the money left --}}
    <div class="mb-6 grid grid-cols-1 sm:grid-cols-2 gap-2 rounded-md bg-gray-50 px-4 py-3 text-sm">
        <p class="text-gray-600">Money taken by <span class="font-medium text-gray-900">{{ $issuedBy }}</span> at the <span class="font-medium text-gray-900">{{ $transaction->deskLabel() }}</span> desk</p>
        @if($transaction->purpose)
            <p class="text-gray-600 sm:text-right">Reason <span class="font-medium text-gray-900">{{ $transaction->purpose }}</span></p>
        @endif
        @if($transaction->payment_method)
            <p class="text-gray-600">Paid by <span class="font-medium text-gray-900">{{ $transaction->payment_method }}</span></p>
        @endif
        @if($transaction->payment_reference)
            <p class="text-gray-600 sm:text-right">Confirmation <span class="font-medium text-gray-900 tabular-nums">{{ $transaction->payment_reference }}</span></p>
        @endif
    </div>

    {{-- Money, one figure to a line. The PlusPay mark sits behind it as the watermark a customer
         reads as part of the paper rather than a picture on it. --}}
    <div class="relative mb-2 overflow-hidden">
        <img src="{{ asset('logo.webp') }}" alt="" aria-hidden="true" width="640" height="232"
            class="pointer-events-none absolute left-1/2 top-1/2 w-56 max-w-full -translate-x-1/2 -translate-y-1/2 opacity-[0.06] sm:w-72">
        <table class="relative w-full border-collapse text-sm">
            <tbody>
                <tr class="receipt-line border-b border-gray-200">
                    <td class="py-2 text-gray-600">Sending money to China</td>
                    <td class="py-2 text-right font-medium text-gray-900 whitespace-nowrap">{{ $transaction->principalLabel() }}</td>
                </tr>
                <tr class="receipt-line border-b border-gray-200">
                    <td class="py-2 text-gray-600">Sending charge</td>
                    <td class="py-2 text-right font-medium text-gray-900 whitespace-nowrap">{{ $transaction->feeLabel() }}</td>
                </tr>
                <tr class="receipt-line bg-green-50">
                    <td class="py-3 pl-2 text-sm font-semibold text-green-900">Total handed over</td>
                    <td class="py-3 pr-2 text-right text-xl font-bold text-green-900 whitespace-nowrap">{{ $transaction->collectedAmount() }}</td>
                </tr>
                <tr class="receipt-line">
                    <td class="py-3 pl-2 text-sm font-semibold text-gray-700">Paid out in China</td>
                    <td class="py-3 pr-2 text-right text-lg font-semibold text-gray-900 whitespace-nowrap">¥{{ number_format($transaction->rmb_amount, 1) }} RMB</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- The two selling prices this booking was quoted at --}}
    <p class="mt-4 mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Prices used on this booking</p>
    <table class="w-full border-collapse text-sm">
        <tbody>
            <tr class="receipt-line border-b border-gray-200">
                <td class="py-2 text-gray-600">Shillings to the dollar</td>
                <td class="py-2 text-right text-gray-900 whitespace-nowrap" title="{{ $usdRate['title'] }}">UGX {{ $usdRate['text'] }} per 1 USD</td>
            </tr>
            <tr class="receipt-line border-b border-gray-200">
                <td class="py-2 text-gray-600">Dollars to the yuan</td>
                <td class="py-2 text-right text-gray-900 whitespace-nowrap" title="{{ $rmbRate['title'] }}">RMB {{ $rmbRate['text'] }} per 1 USD</td>
            </tr>
        </tbody>
    </table>
    <p class="mt-2 mb-6 text-xs text-gray-500">
        Shillings become dollars at the first price and those dollars become yuan at the second. Both
        are saved on this booking, so a later change on the rate card does not move this slip.
    </p>

    {{-- Signatures --}}
    <p class="text-center text-sm font-medium text-gray-800">Thank you for choosing Plus Save.</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 mt-8 mb-6">
        <div>
            <div class="h-10 border-b-2 border-gray-500"></div>
            <p class="mt-1 text-sm font-medium text-gray-900">{{ $issuedBy }}</p>
            <p class="text-xs text-gray-500">Signature and name, for {{ $companyName }}</p>
        </div>
        <div>
            <div class="h-10 border-b-2 border-gray-500"></div>
            <p class="mt-1 text-sm font-medium text-gray-900">{{ $transaction->sender_name }}</p>
            <p class="text-xs text-gray-500">Signature and name, customer</p>
        </div>
    </div>

    <p class="text-xs text-gray-500">
        Keep this receipt as proof of payment. The transfer is found with the number at the top of
        this page, so quote it in any call about this money.
    </p>

    {{-- Footer --}}
    <div class="mt-8 border-t pt-4 text-xs leading-relaxed text-gray-500">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <p class="font-semibold uppercase tracking-wide text-gray-600">Contact us</p>
                @if($companyPhone)
                    <p>Call / WhatsApp: {{ $companyPhone }}</p>
                @endif
                @if($companyEmail)
                    <p>{{ $companyEmail }}</p>
                @endif
                @if($companyWebsite)
                    <p>{{ $companyWebsite }}</p>
                @endif
            </div>
            <div class="sm:text-right">
                @foreach($companyAddress ? preg_split('/\r\n|\r|\n/', $companyAddress) : [] as $line)
                    <p>{{ $line }}</p>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection

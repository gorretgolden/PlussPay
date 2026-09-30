@extends('layouts.app')

@section('title', 'Receipt ' . $transaction->receipt_number)

@section('content')
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

<div class="bg-white rounded-lg shadow p-8 max-w-3xl mx-auto">
    {{-- Header --}}
    <div class="flex justify-between items-start mb-6 border-b pb-4">
        <div>
            <h2 class="text-2xl font-bold text-green-800">PLUSPAY</h2>
            <p class="text-sm text-gray-600">Plus Save Financial Solutions</p>
        </div>
        <div class="text-right">
            <div class="bg-green-800 text-white px-4 py-1 rounded text-sm font-bold inline-block">RECEIPT</div>
            <p class="mt-2 text-sm"><span class="text-gray-500">No.:</span> <span class="font-bold text-red-600 text-lg">{{ $transaction->receipt_number }}</span></p>
            <p class="text-sm"><span class="text-gray-500">Date:</span> {{ $transaction->created_at->format('M d, Y') }}</p>
        </div>
    </div>

    {{-- Title --}}
    <div class="text-center mb-6">
        <h3 class="text-lg font-bold text-gray-800 tracking-wide">SEND MONEY TO CHINA</h3>
    </div>

    {{-- Sender / Receiver --}}
    <div class="grid grid-cols-2 gap-6 mb-6">
        <div>
            <p class="text-sm text-gray-600">Sender's Name: <span class="font-medium text-gray-900">{{ $transaction->sender_name }}</span></p>
            <p class="text-sm text-gray-600">Contact: <span class="font-medium text-gray-900">{{ $transaction->sender_contact }}</span></p>
        </div>
        <div>
            <p class="text-sm text-gray-600">Receiver's Name: <span class="font-medium text-gray-900">{{ $transaction->receiver_name }}</span></p>
            <p class="text-sm text-gray-600">Contact: <span class="font-medium text-gray-900">{{ $transaction->receiver_contact }}</span></p>
        </div>
    </div>

    {{-- Amount Table --}}
    <table class="w-full border-collapse border border-gray-300 mb-6">
        <thead>
            <tr class="bg-gray-100">
                <th class="border border-gray-300 px-4 py-2 text-left text-sm font-medium text-gray-700">Particulars</th>
                <th class="border border-gray-300 px-4 py-2 text-left text-sm font-medium text-gray-700">Rate</th>
                <th class="border border-gray-300 px-4 py-2 text-left text-sm font-medium text-gray-700">Charge</th>
                <th class="border border-gray-300 px-4 py-2 text-left text-sm font-medium text-gray-700">Amount (USD/RMB)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="border border-gray-300 px-4 py-3 text-sm">Send to China ({{ $transaction->currency }})</td>
                <td class="border border-gray-300 px-4 py-3 text-sm">{{ $transaction->exchange_rate }}</td>
                <td class="border border-gray-300 px-4 py-3 text-sm">${{ number_format($transaction->charge_amount, 2) }}</td>
                <td class="border border-gray-300 px-4 py-3 text-sm">
                    {{ $transaction->currency }} {{ number_format($transaction->amount, 2) }}<br>
                    ¥{{ number_format($transaction->rmb_amount, 2) }} RMB
                </td>
            </tr>
        </tbody>
    </table>

    {{-- Status --}}
    <div class="mb-6">
        <span class="px-3 py-1 text-sm rounded-full font-medium
            @if($transaction->status === 'completed') bg-green-100 text-green-800
            @elseif($transaction->status === 'pending') bg-yellow-100 text-yellow-800
            @else bg-red-100 text-red-800 @endif">
            Status: {{ ucfirst($transaction->status) }}
        </span>
    </div>

    {{-- Signatures --}}
    <div class="grid grid-cols-2 gap-8 mt-8 pt-4 border-t">
        <div>
            <p class="text-sm font-medium text-gray-700 mb-8">Signature & Name:</p>
            <p class="text-xs text-gray-500 border-t pt-1">For Plus Save Financial Solutions</p>
        </div>
        <div>
            <p class="text-sm font-medium text-gray-700 mb-8">Signature & Name:</p>
            <p class="text-xs text-gray-500 border-t pt-1">Customer</p>
        </div>
    </div>

    {{-- Footer --}}
    <div class="mt-8 pt-4 border-t text-xs text-gray-500">
        <div class="flex justify-between">
            <div>
                <p class="font-medium">CONTACT US</p>
                <p>CALL / WHATSAPP:</p>
                <p>+256 393 256 744 | +256 702 799 326</p>
            </div>
            <div class="text-right">
                <p>Level 3 Room TA, Saasa Plaza Nabugabo Street</p>
                <p>& Level 2, Old Taxi Park - Aponye mall</p>
                <p>www.plussavefinancialsolutions.com</p>
            </div>
        </div>
    </div>
</div>
@endsection

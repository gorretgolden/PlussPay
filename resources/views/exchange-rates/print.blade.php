<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exchange Rates - PlusPay</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body class="bg-white p-6 text-gray-900">
    <div class="max-w-4xl mx-auto">
        <div class="mb-5 border-b-2 border-green-800 pb-4 text-center">
            <img src="{{ \App\Support\Media::url(\App\Models\Setting::get('login_logo'), 'logo.webp') }}" alt="PlusPay" class="mx-auto mb-2 h-12 w-auto">
            <p class="text-sm font-semibold text-green-800">{{ \App\Support\Branding::companyName() }}</p>
            <h1 class="text-xl font-bold text-green-800">Exchange Rates</h1>
            <p class="text-xs text-gray-600">Generated {{ now()->format('M d, Y h:i A') }} &middot; {{ $rates->count() }} record(s)</p>
            <div class="no-print mt-3 flex justify-center gap-2">
                <a href="{{ route('exchange-rates.pdf', request()->query()) }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm">Download PDF</a>
                <button type="button" onclick="window.print()" class="border border-gray-300 bg-white text-gray-700 px-4 py-2 rounded-md text-sm">Print</button>
            </div>
        </div>

        <table class="w-full text-sm border border-gray-300">
            <thead>
                <tr class="bg-gray-100 text-left">
                    <th class="border border-gray-300 px-2 py-1">From</th>
                    <th class="border border-gray-300 px-2 py-1">To</th>
                    <th class="border border-gray-300 px-2 py-1">Selling</th>
                    <th class="border border-gray-300 px-2 py-1">Buying</th>
                    <th class="border border-gray-300 px-2 py-1">Margin</th>
                    <th class="border border-gray-300 px-2 py-1">Status</th>
                    <th class="border border-gray-300 px-2 py-1">Added by</th>
                    <th class="border border-gray-300 px-2 py-1">Added</th>
                    <th class="border border-gray-300 px-2 py-1">Edited by</th>
                    <th class="border border-gray-300 px-2 py-1">Updated</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rates as $rate)
                @php
                    $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 6, '.', ','), '0'), '.');
                    $selling = $trim($rate->sell_rate ?? $rate->rate);
                    $margin = $rate->buy_rate === null ? null : ((float) ($rate->sell_rate ?? $rate->rate) - (float) $rate->buy_rate) * ($rate->from_currency === 'USD' && $rate->to_currency === 'RMB' ? -1 : 1);
                @endphp
                <tr>
                    <td class="border border-gray-300 px-2 py-1 whitespace-nowrap">{{ $rate->from_currency }}</td>
                    <td class="border border-gray-300 px-2 py-1 whitespace-nowrap">{{ $rate->to_currency }}</td>
                    <td class="border border-gray-300 px-2 py-1 whitespace-nowrap">{{ $selling }}</td>
                    <td class="border border-gray-300 px-2 py-1 whitespace-nowrap">{{ $rate->buy_rate === null ? '-' : $trim($rate->buy_rate) }}</td>
                    <td class="border border-gray-300 px-2 py-1 whitespace-nowrap">{{ $margin === null ? '-' : ($margin > 0 ? '+' : '').$trim($margin) }}</td>
                    <td class="border border-gray-300 px-2 py-1 whitespace-nowrap">{{ $rate->is_active ? 'In use' : 'Earlier' }}</td>
                    <td class="border border-gray-300 px-2 py-1 whitespace-nowrap">{{ $rate->creator?->name ?? '-' }}</td>
                    <td class="border border-gray-300 px-2 py-1 whitespace-nowrap">{{ $rate->created_at->format('Y-m-d h:i A') }}</td>
                    <td class="border border-gray-300 px-2 py-1 whitespace-nowrap">{{ $rate->editor?->name ?? '-' }}</td>
                    <td class="border border-gray-300 px-2 py-1 whitespace-nowrap">{{ $rate->updated_at->format('Y-m-d h:i A') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="10" class="border border-gray-300 px-2 py-2 text-center text-gray-500">No exchange rates match these filters.</td>
                </tr>
                @endforelse
            </tbody>
        </table>

        <p class="text-xs text-gray-500 mt-4">Total records: {{ $rates->count() }}</p>
    </div>

    <script>window.print();</script>
</body>
</html>

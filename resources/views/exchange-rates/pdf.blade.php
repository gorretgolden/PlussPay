<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Exchange Rates - PlusPay</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: "DejaVu Sans", sans-serif; color: #111827; font-size: 9px; margin: 0; }
        .head { text-align: center; border-bottom: 2px solid #14532d; padding-bottom: 10px; margin-bottom: 12px; }
        .head img { height: 46px; width: auto; margin-bottom: 6px; }
        .company { font-size: 11px; color: #14532d; font-weight: bold; }
        h1 { font-size: 17px; margin: 4px 0 2px; color: #14532d; }
        .meta { color: #4b5563; font-size: 9px; }
        .filters { margin: 8px 0 10px; color: #374151; font-size: 8.5px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #14532d; color: #ffffff; font-size: 8px; text-transform: uppercase; letter-spacing: .04em; padding: 6px 5px; border: 1px solid #14532d; text-align: left; }
        td { padding: 5px; border: 1px solid #d1d5db; vertical-align: top; }
        tbody tr:nth-child(even) td { background: #f9fafb; }
        .num { text-align: right; font-family: "DejaVu Sans Mono", monospace; }
        .muted { color: #6b7280; }
        .foot { margin-top: 10px; color: #4b5563; font-size: 8.5px; text-align: center; }
    </style>
</head>
<body>
    <div class="head">
        @if($logo)<img src="{{ $logo }}" alt="PlusPay">@endif
        <div class="company">{{ $company }}</div>
        <h1>Exchange Rates</h1>
        <p class="meta">Generated {{ $generated->format('M d, Y h:i A') }} &middot; {{ $rates->count() }} {{ \Illuminate\Support\Str::plural('record', $rates->count()) }}</p>
    </div>

    @if(!empty($filters))
        <p class="filters">{{ implode('  |  ', $filters) }}</p>
    @endif

    <table>
        <thead>
            <tr>
                <th>Pair</th>
                <th>Selling</th>
                <th>Buying</th>
                <th>Margin</th>
                <th>Status</th>
                <th>Added by</th>
                <th>Added</th>
                <th>Updated by</th>
                <th>Updated</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rates as $rate)
                @php
                    $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 6, '.', ','), '0'), '.');
                    $sell = $rate->sell_rate ?? $rate->rate;
                    $margin = $rate->buy_rate === null ? null : ((float) $sell - (float) $rate->buy_rate) * ($rate->from_currency === 'USD' && $rate->to_currency === 'RMB' ? -1 : 1);
                @endphp
                <tr>
                    <td>{{ $rate->from_currency }} to {{ $rate->to_currency }}</td>
                    <td class="num">{{ $trim($sell) }}</td>
                    <td class="num">{{ $rate->buy_rate === null ? '-' : $trim($rate->buy_rate) }}</td>
                    <td class="num">{{ $margin === null ? '-' : ($margin > 0 ? '+' : '').$trim($margin) }}</td>
                    <td>{{ $rate->is_active ? 'In use' : 'Earlier' }}</td>
                    <td>{{ $rate->creator?->name ?? '-' }}</td>
                    <td class="muted">{{ $rate->created_at->format('M d, Y h:i A') }}</td>
                    <td>{{ $rate->editor?->name ?? '-' }}</td>
                    <td class="muted">{{ $rate->updated_at->format('M d, Y h:i A') }}</td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center; padding:14px;" class="muted">No exchange rates match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="foot">{{ $company }} &middot; Total records: {{ $rates->count() }}</p>
</body>
</html>

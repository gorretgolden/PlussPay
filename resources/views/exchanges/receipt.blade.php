@php
    $companyName = \App\Models\Setting::get('company_name', 'Plus Save Financial Solutions');
    $companyPhone = \App\Models\Setting::get('company_phone');
    $companyAddress = \App\Models\Setting::get('company_address');
    $companyWebsite = \App\Models\Setting::get('company_website');

    $in = \App\Models\Account::format($exchange->in_currency, $exchange->in_amount);
    $out = \App\Models\Account::format($exchange->out_currency, $exchange->out_amount);
    $fee = $exchange->fee > 0 ? \App\Models\Account::format($exchange->in_currency, $exchange->fee) : null;
    $travels = $fee === null ? $in : \App\Models\Account::format($exchange->in_currency, $exchange->pricedAmount());
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exchange slip {{ $exchange->reference }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', monospace;
            width: 80mm;
            margin: 0 auto;
            padding: 5mm;
            font-size: 11px;
            color: #000;
        }
        .header { text-align: center; margin-bottom: 8px; }
        .header h1 { font-size: 18px; font-weight: bold; letter-spacing: 2px; }
        .header .title { font-size: 13px; font-weight: bold; margin: 6px 0; border: 1px solid #000; display: inline-block; padding: 2px 12px; }
        .header .receipt-no { font-size: 14px; color: #c00; font-weight: bold; margin-top: 4px; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 2px; }
        .info-row .label { font-weight: bold; }
        .section-title { text-align: center; font-weight: bold; font-size: 12px; margin: 8px 0 4px; text-decoration: underline; }
        .field { margin-bottom: 4px; }
        .field .label { font-weight: bold; }
        .field .value { border-bottom: 1px dotted #999; min-height: 16px; display: block; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        th, td { border: 1px solid #000; padding: 4px 6px; text-align: left; font-size: 10px; }
        th { background: #f0f0f0; font-weight: bold; }
        .amount-cell { text-align: right; }
        .total-row td { font-weight: bold; }
        .signatures { display: flex; justify-content: space-between; margin-top: 20px; }
        .sig-block { width: 45%; }
        .sig-block .sig-line { border-bottom: 1px solid #000; height: 30px; margin-bottom: 2px; }
        .sig-block .sig-label { font-size: 9px; color: #555; }
        .footer { margin-top: 12px; padding-top: 6px; border-top: 2px solid #000; font-size: 9px; text-align: center; }
        .footer p { margin-bottom: 2px; }
        .void { text-align: center; font-size: 13px; font-weight: bold; color: #c00; border: 2px solid #c00; padding: 4px; margin: 8px 0; }
        @media print {
            body { width: 100%; padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ strtoupper($companyName) }}</h1>
        <div class="title">CURRENCY EXCHANGE</div>
        <div class="receipt-no">No: {{ $exchange->reference }}</div>
    </div>

    @if($exchange->isVoid())
        <div class="void">THIS DEAL WAS CANCELLED</div>
    @endif

    <div class="info-row">
        <span class="label">DATE:</span>
        <span>{{ $exchange->exchange_date->format('M d, Y') }}</span>
    </div>
    <div class="info-row">
        <span class="label">DESK:</span>
        <span>{{ $exchange->deskLabel() ?? '-' }}</span>
    </div>

    <div class="section-title">MONEY CHANGED ACROSS THE COUNTER</div>

    <div class="field">
        <span class="label">Exchanged with:</span>
        <span class="value">{{ $exchange->person_name }}</span>
    </div>
    @if($exchange->payment_method)
    <div class="field">
        <span class="label">Paid how:</span>
        <span class="value">{{ $exchange->payment_method }}</span>
    </div>
    @endif

    <table>
        <thead>
            <tr>
                <th>PARTICULARS</th>
                <th>RATE</th>
                <th style="text-align:right;">AMOUNT</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Handed over to us ({{ $exchange->in_currency }})</td>
                <td></td>
                <td class="amount-cell">{{ $in }}</td>
            </tr>
            @if($fee)
            <tr>
                <td>Commission kept ({{ $exchange->in_currency }})</td>
                <td></td>
                <td class="amount-cell">- {{ $fee }}</td>
            </tr>
            <tr>
                <td>Amount priced ({{ $exchange->in_currency }})</td>
                <td></td>
                <td class="amount-cell">{{ $travels }}</td>
            </tr>
            @endif
            <tr>
                <td>Price given</td>
                <td class="amount-cell">{{ $exchange->quotedRate() ?? 'Not recorded' }}</td>
                <td class="amount-cell"></td>
            </tr>
            @if($exchange->quotedMarketRate())
            <tr>
                <td>Street price on the day</td>
                <td class="amount-cell">{{ $exchange->quotedMarketRate() }}</td>
                <td class="amount-cell"></td>
            </tr>
            @endif
            <tr class="total-row">
                <td>HANDED OVER TO YOU ({{ $exchange->out_currency }})</td>
                <td></td>
                <td class="amount-cell">{{ $out }}</td>
            </tr>
        </tbody>
    </table>

    @if($exchange->rate_reason)
    <div class="field">
        <span class="label">Price agreed by hand because:</span>
        <span class="value">{{ $exchange->rate_reason }}</span>
    </div>
    @endif
    @if($exchange->notes)
    <div class="field">
        <span class="label">Note:</span>
        <span class="value">{{ $exchange->notes }}</span>
    </div>
    @endif

    <p style="font-size:9px; margin-top:6px;">The rates on this slip are the ones saved on the deal the day it was done. Updating the rate card later does not change what is printed here.</p>

    <div class="signatures">
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Signature &amp; Name: For {{ $companyName }}</div>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Signature &amp; Name: Customer</div>
        </div>
    </div>

    <div class="footer">
        @foreach($companyAddress ? preg_split('/\r\n|\r|\n/', $companyAddress) : [] as $line)
            <p>{{ $line }}</p>
        @endforeach
        @if($companyPhone)
            <p>CALL/WHATSAPP: {{ $companyPhone }}</p>
        @endif
        @if($companyWebsite)
            <p>{{ $companyWebsite }}</p>
        @endif
    </div>

    <div class="no-print" style="text-align:center; margin-top:16px;">
        <button onclick="window.print()" style="padding:8px 20px; font-size:14px; cursor:pointer;">Print</button>
        <a href="{{ route('exchanges.show', $exchange) }}" style="margin-left:10px; color:green;">Back to the deal</a>
    </div>
</body>
</html>

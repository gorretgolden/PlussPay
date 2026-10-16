@php
    $companyName = \App\Models\Setting::get('company_name', 'Plus Save Financial Solutions');
    $companyPhone = \App\Models\Setting::get('company_phone');
    $companyAddress = \App\Models\Setting::get('company_address');
    $companyWebsite = \App\Models\Setting::get('company_website');
    // The Plus Save artwork is not in the project yet, so the corner falls back to the saved
    // company name. Dropping public/plussave-logo.png in replaces it without a code change.
    $companyLogo = is_file(public_path('plussave-logo.png'));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt {{ $transaction->receipt_number }}</title>
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
        .header .brand { display: flex; justify-content: space-between; align-items: center; gap: 4px; margin-bottom: 6px; }
        .header .brand img { height: 9mm; width: auto; }
        .header .company { font-size: 11px; font-weight: bold; text-align: right; }
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
        .signatures { display: flex; justify-content: space-between; margin-top: 20px; }
        .sig-block { width: 45%; }
        .sig-block .sig-line { border-bottom: 1px solid #000; height: 30px; margin-bottom: 2px; }
        .sig-block .sig-label { font-size: 9px; color: #555; }
        .footer { margin-top: 12px; padding-top: 6px; border-top: 2px solid #000; font-size: 9px; text-align: center; }
        .footer p { margin-bottom: 2px; }
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 40px;
            color: rgba(0,0,0,0.04);
            font-weight: bold;
            pointer-events: none;
            z-index: 0;
        }
        @media print {
            body { width: 100%; padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="watermark">PLUS SAVE</div>

    <div class="header">
        <div class="brand">
            <img src="{{ asset('logo.webp') }}" alt="PlusPay">
            @if($companyLogo)
                <img src="{{ asset('plussave-logo.png') }}" alt="{{ $companyName }}">
            @else
                <span class="company">{{ strtoupper($companyName) }}</span>
            @endif
        </div>
        <div class="title">RECEIPT</div>
        <div class="receipt-no">No: {{ $transaction->receipt_number }}</div>
    </div>

    <div class="info-row">
        <span class="label">DATE:</span>
        <span>{{ $transaction->created_at->format('M d, Y') }}</span>
    </div>

    <div class="section-title">SEND MONEY TO CHINA</div>

    <div class="field">
        <span class="label">Sender's Name:</span>
        <span class="value">{{ $transaction->sender_name }}</span>
    </div>
    <div class="field">
        <span class="label">Contact:</span>
        <span class="value">{{ $transaction->sender_contact }}</span>
    </div>
    <div class="field">
        <span class="label">Receiver's Name:</span>
        <span class="value">{{ $transaction->receiver_name }}</span>
    </div>
    <div class="field">
        <span class="label">Contact:</span>
        <span class="value">{{ $transaction->receiver_contact }}</span>
    </div>

    @php $usdRate = $transaction->shillingRateCell(); $rmbRate = $transaction->yuanRateCell(); @endphp
    <table>
        <thead>
            <tr>
                <th>PARTICULARS</th>
                <th>RATE (PER 1 USD)</th>
                <th>CHARGE (UGX)</th>
                <th style="text-align:right;">AMOUNT (USD/RMB)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Send to China ({{ $transaction->currency }})</td>
                <td class="amount-cell">
                    UGX {{ $usdRate['text'] }}<br>
                    RMB {{ $rmbRate['text'] }}
                </td>
                <td class="amount-cell">UGX {{ number_format((float) $transaction->charge_ugx) }}</td>
                <td class="amount-cell">
                    {{ $transaction->principalLabel() }}<br>
                    ¥{{ number_format($transaction->rmb_amount, 1) }} RMB
                </td>
            </tr>
            <tr>
                <td>TOTAL HANDED OVER ({{ $transaction->currency }})</td>
                <td class="amount-cell"></td>
                <td class="amount-cell"></td>
                <td class="amount-cell">{{ $transaction->collectedAmount() }}</td>
            </tr>
        </tbody>
    </table>

    <p style="font-size:9px; margin:0 0 8px;">Shillings become dollars at the UGX rate, and those dollars become yuan at the RMB rate. Both selling prices are saved on this booking.</p>

    <div class="signatures">
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Signature & Name: For {{ $companyName }}</div>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Signature & Name: Customer</div>
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
        <button onclick="window.print()" style="padding:8px 20px; font-size:14px; cursor:pointer;">Print Receipt</button>
        <a href="{{ route('receipts.show', $transaction->receipt_number) }}" style="margin-left:10px; color:green;">Back to Receipt</a>
    </div>
</body>
</html>

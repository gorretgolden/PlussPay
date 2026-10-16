@php
    $companyName = \App\Models\Setting::get('company_name', 'Plus Save Financial Solutions');
    $companyPhone = \App\Models\Setting::get('company_phone');
    $companyEmail = \App\Models\Setting::get('company_email');
    $companyAddress = \App\Models\Setting::get('company_address');
    $companyWebsite = \App\Models\Setting::get('company_website');
    // PlusPay leads the slip and the company that signs it holds the right corner. The artwork is
    // read from public/plussave-logo.png, so a rebrand is a file swap and not a code change.
    $companyLogo = is_file(public_path('plussave-logo.png'));
    // Whoever the booking was written into is who took the money, and that name is saved on it.
    $issuedBy = $transaction->creator?->name ?: 'The office';
    $usdRate = $transaction->shillingRateCell();
    $rmbRate = $transaction->yuanRateCell();
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
        .header .brand { display: flex; justify-content: space-between; align-items: center; gap: 4mm; margin-bottom: 6px; }
        .header .brand img { height: 9mm; width: auto; }
        /* The company artwork is a square badge, so it is drawn taller to keep its words readable. */
        .header .brand img.signed { height: 12mm; }
        .header .title { font-size: 13px; font-weight: bold; margin: 6px 0; border: 1px solid #000; display: inline-block; padding: 2px 12px; }
        .header .receipt-no { font-size: 14px; font-weight: bold; margin-top: 4px; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 2px; }
        .info-row .label { font-weight: bold; }
        .section-title { text-align: center; font-weight: bold; font-size: 12px; margin: 8px 0 4px; text-decoration: underline; }
        .field { margin-bottom: 4px; }
        .field .label { font-weight: bold; }
        .field .value { border-bottom: 1px dotted #999; min-height: 16px; display: block; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        th, td { padding: 4px 2px; text-align: left; font-size: 10px; border-bottom: 1px solid #000; }
        th { font-weight: bold; }
        .amount-cell { text-align: right; white-space: nowrap; }
        tr.total td { font-weight: bold; font-size: 12px; }
        .note { font-size: 9px; margin: 0 0 8px; }
        .thanks { text-align: center; font-weight: bold; font-size: 11px; margin: 10px 0 4px; }
        .signatures { display: flex; justify-content: space-between; margin-top: 16px; }
        .sig-block { width: 45%; }
        .sig-block .sig-line { border-bottom: 1px solid #000; height: 30px; margin-bottom: 2px; }
        .sig-block .sig-name { font-size: 10px; font-weight: bold; }
        .sig-block .sig-label { font-size: 9px; color: #555; }
        .footer { margin-top: 12px; padding-top: 6px; border-top: 2px solid #000; font-size: 9px; text-align: center; }
        .footer p { margin-bottom: 2px; }
        /* The PlusPay mark sits behind the money, the way it sits behind the paper copy. */
        .money { position: relative; }
        .money table { position: relative; z-index: 1; }
        .money .mark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 46mm;
            max-width: 100%;
            opacity: 0.08;
            pointer-events: none;
        }
        @media print {
            body { width: 100%; padding: 0; }
            .no-print { display: none; }
            table, .sig-block, .footer { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">
            <img src="{{ asset('logo.webp') }}" alt="PlusPay">
            @if($companyLogo)
                <img class="signed" src="{{ asset('plussave-logo.png') }}" alt="{{ $companyName }}">
            @endif
        </div>
        <div class="title">RECEIPT</div>
        <div class="receipt-no">No: {{ $transaction->receipt_number }}</div>
        <div style="font-size:10px;">{{ $transaction->created_at->format('M d, Y h:i A') }} &nbsp;|&nbsp; {{ strtoupper($transaction->status) }}</div>
    </div>

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
    <div class="field">
        <span class="label">Money taken by:</span>
        <span class="value">{{ $issuedBy }}</span>
    </div>
    @if($transaction->purpose)
    <div class="field">
        <span class="label">Reason:</span>
        <span class="value">{{ $transaction->purpose }}</span>
    </div>
    @endif

    <div class="money">
        <img class="mark" src="{{ asset('logo.webp') }}" alt="">
        <table>
            <tbody>
                <tr>
                    <td>SENDING MONEY TO CHINA</td>
                    <td class="amount-cell">{{ $transaction->principalLabel() }}</td>
                </tr>
                <tr>
                    <td>SENDING CHARGE</td>
                    <td class="amount-cell">{{ $transaction->feeLabel() }}</td>
                </tr>
                <tr class="total">
                    <td>TOTAL HANDED OVER</td>
                    <td class="amount-cell">{{ $transaction->collectedAmount() }}</td>
                </tr>
                <tr>
                    <td>PAID OUT IN CHINA</td>
                    <td class="amount-cell">¥{{ number_format($transaction->rmb_amount, 1) }} RMB</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section-title">PRICES PER 1 USD</div>
    <table class="prices">
        <tbody>
            <tr>
                <td>SHILLINGS TO THE DOLLAR</td>
                <td class="amount-cell" title="{{ $usdRate['title'] }}">UGX {{ $usdRate['text'] }}</td>
            </tr>
            <tr>
                <td>DOLLARS TO THE YUAN</td>
                <td class="amount-cell" title="{{ $rmbRate['title'] }}">RMB {{ $rmbRate['text'] }}</td>
            </tr>
        </tbody>
    </table>

    <p class="note">Shillings become dollars at the first price and those dollars become yuan at the
        second. Both are saved on this booking, so a later change on the rate card does not move this slip.</p>

    <div class="thanks">Thank you for choosing Plus Save.</div>

    <div class="signatures">
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-name">{{ $issuedBy }}</div>
            <div class="sig-label">Signature and name, for {{ $companyName }}</div>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-name">{{ $transaction->sender_name }}</div>
            <div class="sig-label">Signature and name, customer</div>
        </div>
    </div>

    <p class="note" style="margin-top:10px;">Keep this receipt as proof of payment. The transfer is found
        with the number at the top of this slip, so quote it in any call about this money.</p>

    <div class="footer">
        @foreach($companyAddress ? preg_split('/\r\n|\r|\n/', $companyAddress) : [] as $line)
            <p>{{ $line }}</p>
        @endforeach
        @if($companyPhone)
            <p>CALL/WHATSAPP: {{ $companyPhone }}</p>
        @endif
        @if($companyEmail)
            <p>{{ $companyEmail }}</p>
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

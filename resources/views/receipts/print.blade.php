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
        .header h1 { font-size: 18px; font-weight: bold; letter-spacing: 2px; }
        .header .company { font-size: 10px; color: #555; }
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
        <h1>PLUSPAY</h1>
        <div class="company">Plus Save Financial Solutions</div>
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

    <table>
        <thead>
            <tr>
                <th>PARTICULARS</th>
                <th>RATE</th>
                <th>CHARGE</th>
                <th style="text-align:right;">AMOUNT (USD/RMB)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Send to China ({{ $transaction->currency }})</td>
                <td class="amount-cell">{{ $transaction->exchange_rate }}</td>
                <td class="amount-cell">${{ number_format($transaction->charge_amount, 2) }}</td>
                <td class="amount-cell">
                    {{ $transaction->currency }} {{ number_format($transaction->amount, 2) }}<br>
                    ¥{{ number_format($transaction->rmb_amount, 2) }} RMB
                </td>
            </tr>
        </tbody>
    </table>

    <div class="signatures">
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Signature & Name: For Plus Save Financial Solutions</div>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Signature & Name: Customer</div>
        </div>
    </div>

    <div class="footer">
        <p>Level 3 Room TA, Saasa Plaza Nabugabo Street</p>
        <p>& Level 2, Old Taxi Park - Aponye mall</p>
        <p>CALL/WHATSAPP: +256 393 256 744 | +256 702 799 326</p>
        <p>www.plussavefinancialsolutions.com</p>
    </div>

    <div class="no-print" style="text-align:center; margin-top:16px;">
        <button onclick="window.print()" style="padding:8px 20px; font-size:14px; cursor:pointer;">Print Receipt</button>
        <a href="{{ route('receipts.show', $transaction->receipt_number) }}" style="margin-left:10px; color:blue;">Back to Receipt</a>
    </div>
</body>
</html>

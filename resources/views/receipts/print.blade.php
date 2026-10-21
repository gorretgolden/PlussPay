@php($companyName = \App\Models\Setting::get('company_name', 'Plus Save Financial Solutions'))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt {{ $transaction->receipt_number }} - {{ $companyName }}</title>
    @include('receipts.partials.slip-styles')
    <style>
        html, body { margin: 0; padding: 0; }
        body {
            padding: 18px 16px 48px;
            background: #e5e7eb;
            color: #1b241e;
            font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
        }
        .slip-tools {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            max-width: 148mm;
            margin: 0 auto 12px;
            font-size: 13px;
            color: #374151;
        }
        .slip-tools-title { font-weight: 700; color: #111827; }
        .slip-tools-sub { margin-top: 2px; color: #6b7280; font-size: 11px; }
        .slip-tools-actions { display: flex; align-items: center; gap: 8px; }
        .slip-tools a,
        .slip-tools button {
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-family: inherit;
            text-decoration: none;
            cursor: pointer;
        }
        .slip-tools a { border: 1px solid #d1d5db; background: #fff; color: #374151; }
        .slip-tools button { border: 1px solid #14532d; background: #14532d; color: #fff; font-weight: 600; }
        .slip-tools button:hover { background: #166534; }
        .slip-tools a:focus-visible,
        .slip-tools button:focus-visible { outline: 2px solid #15803d; outline-offset: 3px; }
        .slip-sheet { max-width: 148mm; margin: 0 auto; }

        @media print {
            @page { size: A5 portrait; margin: 6mm; }
            body { padding: 0; background: #fff; }
            .slip-tools { display: none !important; }
            .slip-sheet { max-width: none; margin: 0; }
            .slip {
                max-width: none;
                padding: 0;
                border: 0;
                border-radius: 0;
                background: #fff;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="slip-tools">
        <div>
            <p class="slip-tools-title">Receipt {{ $transaction->receipt_number }}</p>
            <p class="slip-tools-sub">A5 portrait, print at 100%.</p>
        </div>
        <div class="slip-tools-actions">
            <a href="{{ route('receipts.show', $transaction->receipt_number) }}">Back to receipt</a>
            <button type="button" onclick="window.print()">Print receipt</button>
        </div>
    </div>

    <div class="slip-sheet">
        @include('receipts.partials.slip')
    </div>
</body>
</html>

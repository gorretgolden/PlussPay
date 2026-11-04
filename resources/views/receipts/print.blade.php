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
        .slip-tools button { border: 1px solid var(--brand-900, #14532d); background: var(--brand-900, #14532d); color: #fff; font-weight: 600; }
        .slip-tools button:hover { background: var(--brand-800, #166534); }
        .slip-tools a:focus-visible,
        .slip-tools button:focus-visible { outline: 2px solid var(--brand-700, #15803d); outline-offset: 3px; }
        .slip-sheet {
            max-width: 148mm;
            margin: 0 auto;
            container-type: inline-size;
            container-name: slip-sheet;
        }
        body[data-paper="pos"] .slip-sheet { max-width: 74mm; }

        .slip-paper { display: inline-flex; border: 1px solid #d1d5db; border-radius: 6px; overflow: hidden; }
        .slip-paper button {
            padding: 8px 12px;
            border: 0;
            border-radius: 0;
            background: #fff;
            color: #374151;
            font-size: 13px;
            font-weight: 500;
        }
        .slip-paper button + button { border-left: 1px solid #d1d5db; }
        .slip-paper button.is-on { background: #eef2f6; color: #111827; font-weight: 700; }
        .slip-paper button:hover { background: #f3f4f6; }
        .slip-paper button.is-on:hover { background: #e5eaf0; }

        @media print {
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
    {{-- Two papers, one page box each, and only the chosen one is switched on. A size the printer
         is not given is a size it prints anyway, so the roll has to carry its own @page rule rather
         than hope the narrow layout is enough. --}}
    <style id="page-a5" media="print">
        @page { size: A5 portrait; margin: 6mm; }
    </style>
    <style id="page-pos" media="pluspay-off">
        @page { size: 80mm auto; margin: 3mm; }
    </style>
</head>
<body>
    <div class="slip-tools">
        <div>
            <p class="slip-tools-title">Receipt {{ $transaction->receipt_number }}</p>
            <p class="slip-tools-sub" id="paper_note">A5 portrait, print at 100%.</p>
        </div>
        <div class="slip-tools-actions">
            <div class="slip-paper" role="group" aria-label="Choose the paper the receipt prints on">
                <button type="button" data-paper="a5" aria-pressed="true" class="is-on">A5 slip</button>
                <button type="button" data-paper="pos" aria-pressed="false">POS machine</button>
            </div>
            <a href="{{ route('receipts.show', $transaction->receipt_number) }}">Back to receipt</a>
            <button type="button" onclick="window.print()">Print receipt</button>
        </div>
    </div>

    <div class="slip-sheet">
        @include('receipts.partials.slip')
    </div>

    <script>
        // The desk keeps one paper choice per till, so the slip opens on the paper it is actually
        // printed on instead of asking every receipt. An unknown saved value falls back to A5.
        const NOTE = {
            a5: 'A5 portrait, print at 100%.',
            pos: '80mm roll, print at 100%. The slip narrows itself to the roll.',
        };

        const pageA5 = document.getElementById('page-a5');
        const pagePos = document.getElementById('page-pos');
        const note = document.getElementById('paper_note');
        const buttons = Array.from(document.querySelectorAll('.slip-paper button'));

        function usePaper(paper) {
            const pos = paper === 'pos';

            document.body.dataset.paper = pos ? 'pos' : 'a5';
            pageA5.media = pos ? 'pluspay-off' : 'print';
            pagePos.media = pos ? 'print' : 'pluspay-off';
            note.textContent = NOTE[pos ? 'pos' : 'a5'];

            buttons.forEach((button) => {
                const on = button.dataset.paper === (pos ? 'pos' : 'a5');
                button.classList.toggle('is-on', on);
                button.setAttribute('aria-pressed', String(on));
            });
        }

        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                usePaper(button.dataset.paper);
                localStorage.setItem('pluspay-receipt-paper', button.dataset.paper);
            });
        });

        usePaper(localStorage.getItem('pluspay-receipt-paper') === 'pos' ? 'pos' : 'a5');
    </script>
</body>
</html>

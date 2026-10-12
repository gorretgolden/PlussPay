<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

class ReportsController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());
        $reportType = $request->input('type', 'hub');

        $query = Transaction::visibleTo(auth()->user())
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);

        $transactions = (clone $query)->latest()->get();

        $stats = [
            'total_transactions' => $transactions->count(),
            'completed' => $transactions->where('status', 'completed')->count(),
            'pending' => $transactions->where('status', 'pending')->count(),
            'cancelled' => $transactions->where('status', 'cancelled')->count(),
            'total_usd_sent' => $transactions->where('status', 'completed')->sum('total_usd_equivalent'),
            'total_rmb_sent' => $transactions->where('status', 'completed')->sum('rmb_amount'),
            'total_revenue' => $transactions->where('status', 'completed')->sum('charge_amount'),
            'avg_transaction' => $transactions->where('status', 'completed')->count()
                ? $transactions->where('status', 'completed')->avg('total_usd_equivalent')
                : 0,
        ];

        $profit = $this->profitTotals($transactions->where('status', 'completed'), $stats['total_usd_sent']);

        // A transfer counts as unpriced whenever the buying rate is missing or zero, since
        // both leave the margin at zero. Rows written before the column existed are NULL,
        // but a booking made with only a selling rate configured saves as 0.000000.
        $unpriced = $transactions->where('status', 'completed')
            ->filter(fn (Transaction $t) => (float) $t->rate_buy_ugx <= 0);
        $unpricedCount = $unpriced->count();
        $unpricedReceipts = $unpriced->pluck('receipt_number')->values();

        $byCurrency = $transactions->where('status', 'completed')
            ->groupBy('currency')
            ->map(fn ($items) => [
                'count' => $items->count(),
                'total' => $items->sum('amount'),
                'usd_equivalent' => $items->sum('total_usd_equivalent'),
            ]);

        $byAgent = Transaction::with('creator')
            ->visibleTo(auth()->user())
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->where('status', 'completed')
            ->select('created_by', DB::raw('count(*) as count'), DB::raw('sum(total_usd_equivalent) as total_usd'), DB::raw('sum(charge_amount) as total_revenue'), DB::raw('sum(charge_ugx) as fee_ugx'), DB::raw('sum(fx_profit_ugx) as fx_ugx'), DB::raw('sum(fx_profit_usd) as fx_usd'))
            ->groupBy('created_by')
            ->get();

        $dailySummary = Transaction::visibleTo(auth()->user())
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->where('status', 'completed')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'), DB::raw('sum(total_usd_equivalent) as total_usd'), DB::raw('sum(charge_amount) as revenue'), DB::raw('sum(charge_ugx) as fee_ugx'), DB::raw('sum(fx_profit_ugx) as fx_ugx'), DB::raw('sum(fx_profit_usd) as fx_usd'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        return view('reports.index', compact('stats', 'profit', 'unpricedCount', 'unpricedReceipts', 'byCurrency', 'byAgent', 'dailySummary', 'dateFrom', 'dateTo', 'transactions', 'reportType'));
    }

    private function profitTotals($completed, float $usdSent): array
    {
        $feeUgx = (float) $completed->sum('charge_ugx');
        $feeUsd = (float) $completed->sum('charge_amount');
        $fxUgx = (float) $completed->sum('fx_profit_ugx');
        $fxUsd = (float) $completed->sum('fx_profit_usd');
        $totalUgx = $feeUgx + $fxUgx;
        $totalUsd = $feeUsd + $fxUsd;

        // Read in yuan at the rate each transfer was actually booked at, which is the RMB
        // it delivered divided by the dollars it was worth. Rows saved before those two
        // figures existed fall back to the USD/RMB rate standing on the rate sheet today.
        $fallback = (float) (ExchangeRate::getActiveRate('USD', 'RMB')->rate ?? 0);
        $rate = fn (Transaction $t) => (float) $t->total_usd_equivalent > 0 && (float) $t->rmb_amount > 0
            ? (float) $t->rmb_amount / (float) $t->total_usd_equivalent
            : $fallback;

        $feeRmb = (float) $completed->sum(fn (Transaction $t) => (float) $t->charge_amount * $rate($t));
        $fxRmb = (float) $completed->sum(fn (Transaction $t) => (float) $t->fx_profit_usd * $rate($t));
        $totalRmb = $feeRmb + $fxRmb;

        return [
            'fee_ugx' => $feeUgx,
            'fee_usd' => $feeUsd,
            'fee_rmb' => $feeRmb,
            'fx_ugx' => $fxUgx,
            'fx_usd' => $fxUsd,
            'fx_rmb' => $fxRmb,
            'total_ugx' => $totalUgx,
            'total_usd' => $totalUsd,
            'total_rmb' => $totalRmb,
            'per_dollar_ugx' => $usdSent > 0 ? $totalUgx / $usdSent : 0,
            'per_dollar_usd' => $usdSent > 0 ? $totalUsd / $usdSent : 0,
            'per_dollar_rmb' => $usdSent > 0 ? $totalRmb / $usdSent : 0,
        ];
    }

    public function exportExcel(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());

        $query = Transaction::visibleTo(auth()->user())
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('agent_id')) {
            $query->where('created_by', $request->agent_id);
        }

        if ($request->filled('currency')) {
            $query->where('currency', $request->currency);
        }

        if ($request->filled('search')) {
            $search = '%'.addcslashes($request->search, '%_\\').'%';
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', $search)
                  ->orWhere('sender_name', 'like', $search)
                  ->orWhere('receiver_name', 'like', $search);
            });
        }

        $transactions = $query->with('creator')->latest()->get();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'exported',
            'description' => "Exported report {$dateFrom} to {$dateTo} (" . $transactions->count() . ' transaction(s))',
            'new_value' => (string) $transactions->count(),
        ]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Transactions');

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '14532D']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];

        $headers = ['Receipt #', 'Date', 'Sender Name', 'Sender Contact', 'Receiver Name', 'Receiver Contact', 'Currency', 'Amount', 'USD Equivalent', 'Exchange Rate', 'RMB Amount', 'Charge (UGX)', 'Charge (USD)', 'Status', 'Agent', 'Dollar Margin (UGX)', 'Total Profit (UGX)', 'Total Profit (USD)'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $col++;
        }
        $sheet->getStyle('A1:R1')->applyFromArray($headerStyle);

        $row = 2;
        foreach ($transactions as $t) {
            // Text cells are forced to TYPE_STRING so a name starting with =, + or @
            // cannot become an executable formula when the report is opened.
            $this->putText($sheet, 'A' . $row, (string) $t->receipt_number);
            $this->putText($sheet, 'B' . $row, $t->created_at->format('Y-m-d h:i A'));
            $this->putText($sheet, 'C' . $row, (string) $t->sender_name);
            $this->putText($sheet, 'D' . $row, (string) $t->sender_contact);
            $this->putText($sheet, 'E' . $row, (string) $t->receiver_name);
            $this->putText($sheet, 'F' . $row, (string) $t->receiver_contact);
            $this->putText($sheet, 'G' . $row, (string) $t->currency);
            $sheet->setCellValue('H' . $row, (float) $t->amount);
            $sheet->setCellValue('I' . $row, (float) $t->total_usd_equivalent);
            $sheet->setCellValue('J' . $row, (float) $t->exchange_rate);
            $sheet->setCellValue('K' . $row, (float) $t->rmb_amount);
            $sheet->setCellValue('L' . $row, (float) $t->charge_ugx);
            $sheet->setCellValue('M' . $row, (float) $t->charge_amount);
            $this->putText($sheet, 'N' . $row, ucfirst((string) $t->status));
            $this->putText($sheet, 'O' . $row, (string) ($t->creator?->name ?? '-'));
            $sheet->setCellValue('P' . $row, (float) $t->fx_profit_ugx);
            $sheet->setCellValue('Q' . $row, (float) $t->charge_ugx + (float) $t->fx_profit_ugx);
            $sheet->setCellValue('R' . $row, (float) $t->charge_amount + (float) $t->fx_profit_usd);
            $row++;
        }

        $sheet->getStyle('H2:H' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('I2:I' . ($row - 1))->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet->getStyle('K2:K' . ($row - 1))->getNumberFormat()->setFormatCode('¥#,##0.00');
        $sheet->getStyle('M2:M' . ($row - 1))->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet->getStyle('L2:L' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('P2:Q' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('R2:R' . ($row - 1))->getNumberFormat()->setFormatCode('$#,##0.00');

        $sheet->getStyle('A2:R' . ($row - 1))->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        foreach (range('A', 'R') as $colLetter) {
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        $filename = 'PlusPay_Report_' . $dateFrom . '_to_' . $dateTo . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    private function putText($sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }
}

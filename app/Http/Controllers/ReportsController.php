<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $query = Transaction::whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);

        if (auth()->user()->hasRole('agent')) {
            $query->where('created_by', auth()->id());
        }

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

        $byCurrency = $transactions->where('status', 'completed')
            ->groupBy('currency')
            ->map(fn ($items) => [
                'count' => $items->count(),
                'total' => $items->sum('amount'),
                'usd_equivalent' => $items->sum('total_usd_equivalent'),
            ]);

        $byAgent = Transaction::with('creator')
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->where('status', 'completed')
            ->select('created_by', DB::raw('count(*) as count'), DB::raw('sum(total_usd_equivalent) as total_usd'), DB::raw('sum(charge_amount) as total_revenue'))
            ->groupBy('created_by')
            ->get();

        $dailySummary = Transaction::whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->where('status', 'completed')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'), DB::raw('sum(total_usd_equivalent) as total_usd'), DB::raw('sum(charge_amount) as revenue'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        return view('reports.index', compact('stats', 'byCurrency', 'byAgent', 'dailySummary', 'dateFrom', 'dateTo', 'transactions'));
    }

    public function exportExcel(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());

        $query = Transaction::whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);

        if (auth()->user()->hasRole('agent')) {
            $query->where('created_by', auth()->id());
        }

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
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                  ->orWhere('sender_name', 'like', "%{$search}%")
                  ->orWhere('receiver_name', 'like', "%{$search}%");
            });
        }

        $transactions = $query->with('creator')->latest()->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Transactions');

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '14532D']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];

        $headers = ['Receipt #', 'Date', 'Sender Name', 'Sender Contact', 'Receiver Name', 'Receiver Contact', 'Currency', 'Amount', 'USD Equivalent', 'Exchange Rate', 'RMB Amount', 'Charge Rate %', 'Charge Amount', 'Status', 'Agent'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $col++;
        }
        $sheet->getStyle('A1:O1')->applyFromArray($headerStyle);

        $row = 2;
        foreach ($transactions as $t) {
            $sheet->setCellValue('A' . $row, $t->receipt_number);
            $sheet->setCellValue('B' . $row, $t->created_at->format('Y-m-d H:i'));
            $sheet->setCellValue('C' . $row, $t->sender_name);
            $sheet->setCellValue('D' . $row, $t->sender_contact);
            $sheet->setCellValue('E' . $row, $t->receiver_name);
            $sheet->setCellValue('F' . $row, $t->receiver_contact);
            $sheet->setCellValue('G' . $row, $t->currency);
            $sheet->setCellValue('H' . $row, (float) $t->amount);
            $sheet->setCellValue('I' . $row, (float) $t->total_usd_equivalent);
            $sheet->setCellValue('J' . $row, (float) $t->exchange_rate);
            $sheet->setCellValue('K' . $row, (float) $t->rmb_amount);
            $sheet->setCellValue('L' . $row, (float) $t->charge_rate);
            $sheet->setCellValue('M' . $row, (float) $t->charge_amount);
            $sheet->setCellValue('N' . $row, ucfirst($t->status));
            $sheet->setCellValue('O' . $row, $t->creator?->name ?? '-');
            $row++;
        }

        $sheet->getStyle('H2:H' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('I2:I' . ($row - 1))->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet->getStyle('K2:K' . ($row - 1))->getNumberFormat()->setFormatCode('¥#,##0.00');
        $sheet->getStyle('M2:M' . ($row - 1))->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet->getStyle('L2:L' . ($row - 1))->getNumberFormat()->setFormatCode('0.00"%"');

        $sheet->getStyle('A2:O' . ($row - 1))->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        foreach (range('A', 'O') as $colLetter) {
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
}

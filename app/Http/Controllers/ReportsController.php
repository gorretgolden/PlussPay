<?php

namespace App\Http\Controllers;

use App\Models\AccountEntry;
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

        // The yuan figures in these two tables have to be read line by line, so they carry the
        // same rule the profit cards use. See rmbRateExpression().
        [$rmbRate, $rmbBinding] = $this->rmbRateExpression();

        $byAgent = Transaction::with('creator')
            ->visibleTo(auth()->user())
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->where('status', 'completed')
            ->selectRaw('created_by, count(*) as count, sum(total_usd_equivalent) as total_usd, sum(charge_amount) as total_revenue, sum(charge_ugx) as fee_ugx, sum(fx_profit_ugx) as fx_ugx, sum(fx_profit_usd) as fx_usd, sum(charge_amount * '.$rmbRate.') as fee_rmb, sum(fx_profit_usd * '.$rmbRate.') as fx_rmb', array_merge($rmbBinding, $rmbBinding))
            ->groupBy('created_by')
            ->get();

        $dailySummary = Transaction::visibleTo(auth()->user())
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->where('status', 'completed')
            ->selectRaw('DATE(created_at) as date, count(*) as count, sum(total_usd_equivalent) as total_usd, sum(charge_amount) as revenue, sum(charge_ugx) as fee_ugx, sum(fx_profit_ugx) as fx_ugx, sum(fx_profit_usd) as fx_usd, sum(charge_amount * '.$rmbRate.') as fee_rmb, sum(fx_profit_usd * '.$rmbRate.') as fx_rmb', array_merge($rmbBinding, $rmbBinding))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        // Customers come from the booking rows rather than the directory, because the directory
        // only holds a name and a phone and carries no volume. Each line is grouped under the
        // sender name as it was typed on the transfer, so a misspelling stays its own customer.
        $byCustomer = Transaction::visibleTo(auth()->user())
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->where('status', 'completed')
            ->where('sender_name', '!=', '')
            ->selectRaw('sender_name, count(*) as count, sum(total_usd_equivalent) as total_usd, sum(rmb_amount) as rmb_delivered, sum(charge_ugx) as fee_ugx, max(created_at) as last_transfer')
            ->groupBy('sender_name')
            ->orderByDesc('total_usd')
            ->get();

        $byRate = $this->rateBookings($dateFrom, $dateTo, auth()->user());

        $commission = $this->commissionTotals($dateFrom, $dateTo, auth()->user());

        return view('reports.index', compact('stats', 'profit', 'unpricedCount', 'unpricedReceipts', 'byCurrency', 'byAgent', 'dailySummary', 'byCustomer', 'byRate', 'dateFrom', 'dateTo', 'transactions', 'reportType', 'commission'));
    }

    /**
     * The money that moved at each dollar-to-yuan rate in the period, next to what those
     * transfers earned. The rate sheet on its own only records what was set; pairing it with
     * the bookings made under it shows which price actually brought the business.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function rateBookings(string $dateFrom, string $dateTo, User $viewer)
    {
        $rows = Transaction::visibleTo($viewer)
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->where('status', 'completed')
            ->selectRaw('exchange_rate, count(*) as count, sum(total_usd_equivalent) as total_usd, sum(rmb_amount) as rmb_delivered, sum(charge_ugx) as fee_ugx, sum(fx_profit_ugx) as fx_ugx, min(created_at) as first_booked, max(created_at) as last_booked')
            ->groupBy('exchange_rate')
            ->orderByDesc('last_booked')
            ->get();

        $current = (float) (ExchangeRate::getActiveRate('USD', 'RMB')->rate ?? 0);

        return $rows->map(function ($row) use ($current) {
            $row->is_current = $current > 0 && abs((float) $row->exchange_rate - $current) < 0.000001;

            return $row;
        });
    }

    /**
     * What one dollar of a line is worth in yuan, read at the rate that transfer was actually
     * booked at: the yuan it delivered over the dollars it was worth. A row missing either
     * figure falls back to the USD/RMB rate standing on the sheet today, which is the same
     * rule the profit cards use, so the cards and these tables cannot disagree.
     *
     * The multiply by 1.0 is not decoration. Both columns hold whole numbers whenever a
     * transfer delivered an exact number of yuan, and sqlite then divides them as integers,
     * turning a 7.2 rate into 7 and quietly under-pricing every yuan figure.
     *
     * @return array{0: string, 1: array<int, float>}
     */
    private function rmbRateExpression(): array
    {
        $fallback = (float) (ExchangeRate::getActiveRate('USD', 'RMB')->rate ?? 0);

        return [
            '(CASE WHEN total_usd_equivalent > 0 AND rmb_amount > 0 THEN rmb_amount * 1.0 / total_usd_equivalent ELSE ? END)',
            [$fallback],
        ];
    }

    /**
     * Commission comes from the ledger rather than the transfer rows, so a cancelled transfer
     * takes its commission back here exactly as it does in the wallet, and a hand-posted wallet
     * entry still shows up. Whoever is not an admin sees only their own figures.
     *
     * @return array{rows: \Illuminate\Support\Collection, earned: float, reversed: float, net: float, transfers: int, everyone: bool}
     */
    private function commissionTotals(string $dateFrom, string $dateTo, User $viewer): array
    {
        $everyone = $viewer->hasRole(['admin', 'superadmin']);

        $rows = AccountEntry::query()
            ->join('accounts', 'account_entries.account_id', '=', 'accounts.id')
            ->join('users', 'users.id', '=', 'accounts.owner_id')
            ->whereIn('account_entries.type', ['agent_fee', 'agent_fee_reversal'])
            // entry_date is a date cast, so it saves as '2026-10-12 00:00:00' and a plain
            // whereBetween would drop the last day of the period.
            ->whereDate('account_entries.entry_date', '>=', $dateFrom)
            ->whereDate('account_entries.entry_date', '<=', $dateTo)
            ->when(! $everyone, fn ($query) => $query->where('accounts.owner_id', $viewer->id))
            ->select(
                'accounts.owner_id',
                'users.name',
                DB::raw("SUM(CASE WHEN account_entries.type = 'agent_fee' THEN account_entries.amount ELSE 0 END) as earned"),
                DB::raw("SUM(CASE WHEN account_entries.type = 'agent_fee_reversal' THEN account_entries.amount ELSE 0 END) as reversed"),
                DB::raw("COUNT(DISTINCT CASE WHEN account_entries.type = 'agent_fee' THEN account_entries.transaction_id END) as transfers"),
            )
            ->groupBy('accounts.owner_id', 'users.name')
            ->orderByDesc('earned')
            ->get();

        // The ledger only carries the id, so the role label that tells her an earning is
        // Staff's rather than an Agent's comes from the accounts themselves.
        $people = User::whereIn('id', $rows->pluck('owner_id'))->with('roles')->get()->keyBy('id');

        $rows = $rows->map(function ($row) use ($people) {
            $row->role = RolePermissionController::displayName($people->get($row->owner_id)?->getRoleNames()->first() ?? '');
            $row->net = (float) $row->earned - (float) $row->reversed;

            return $row;
        });

        return [
            'rows' => $rows,
            'earned' => (float) $rows->sum('earned'),
            'reversed' => (float) $rows->sum('reversed'),
            'net' => (float) $rows->sum('net'),
            'transfers' => (int) $rows->sum('transfers'),
            'everyone' => $everyone,
        ];
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

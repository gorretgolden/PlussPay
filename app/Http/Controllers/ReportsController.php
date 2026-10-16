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

        $completed = $transactions->where('status', 'completed');

        $stats = [
            'total_transactions' => $transactions->count(),
            'completed' => $completed->count(),
            'pending' => $transactions->where('status', 'pending')->count(),
            'cancelled' => $transactions->where('status', 'cancelled')->count(),
            'total_usd_sent' => $completed->sum('total_usd_equivalent'),
            'total_rmb_sent' => $completed->sum('rmb_amount'),
            // Yuan only counts as delivered when it has actually reached a hand in China, so the
            // booked figure is split by the two moments that move it: the dollars leaving Uganda
            // and the yuan being paid out. The three add back to total_rmb_sent.
            'rmb_in_uganda' => $completed->whereNull('settled_at')->whereNull('delivered_at')->sum('rmb_amount'),
            'rmb_in_china' => $completed->whereNotNull('settled_at')->whereNull('delivered_at')->sum('rmb_amount'),
            'rmb_paid' => $completed->whereNotNull('delivered_at')->sum('rmb_amount'),
            'total_revenue' => $completed->sum('charge_amount'),
            'avg_transaction' => $completed->count()
                ? $completed->avg('total_usd_equivalent')
                : 0,
        ];

        $profit = $this->profitTotals($completed, $stats['total_usd_sent']);

        // One transfer counts as unpriced when either rate is missing: the buying rate on the
        // dollar is what prices the shilling leg, and the yuan figure is what prices the leg
        // paid out in China. A row written before those columns existed has neither, and a
        // booking made with only a selling rate configured saves the rate as 0.000000. Either
        // way the exchange profit is short, so the two are reported as one count.
        $unpriced = $transactions->where('status', 'completed')
            ->filter(fn (Transaction $t) => (float) $t->rate_buy_ugx <= 0 || $t->usd_rmb_profit_cny === null);
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
        // same rule the profit cards use. See Transaction::yuanRateSql().
        [$rmbRate, $rmbBinding] = Transaction::yuanRateSql();

        // Exchange profit is both spreads on one line, and the yuan column is the whole overall
        // profit converted at the rate each transfer was booked at, which is why the charge and
        // the dollar leg travel as dollars and the yuan leg already arrives in yuan.
        $profitCols = 'sum(coalesce(fx_profit_ugx, 0) + coalesce(usd_rmb_profit_ugx, 0)) as exchange_ugx, '
            . 'sum(coalesce(charge_amount, 0) * ('.$rmbRate.') + coalesce(fx_profit_usd, 0) * ('.$rmbRate.') + coalesce(usd_rmb_profit_cny, 0)) as total_rmb';
        $profitBind = array_merge($rmbBinding, $rmbBinding);

        $byAgent = Transaction::with('creator')
            ->visibleTo(auth()->user())
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->where('status', 'completed')
            ->selectRaw('created_by, count(*) as count, sum(total_usd_equivalent) as total_usd, sum(charge_ugx) as fee_ugx, '.$profitCols, $profitBind)
            ->groupBy('created_by')
            ->get();

        $dailySummary = Transaction::visibleTo(auth()->user())
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->where('status', 'completed')
            ->selectRaw('DATE(created_at) as date, count(*) as count, sum(total_usd_equivalent) as total_usd, sum(charge_ugx) as fee_ugx, '.$profitCols, $profitBind)
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
            ->selectRaw('sender_name, count(*) as count, sum(total_usd_equivalent) as total_usd, sum(rmb_amount) as rmb_booked, sum(charge_ugx) as fee_ugx, max(created_at) as last_transfer')
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
            ->selectRaw('exchange_rate, count(*) as count, sum(total_usd_equivalent) as total_usd, sum(rmb_amount) as rmb_booked, sum(charge_ugx) as fee_ugx, sum(coalesce(fx_profit_ugx, 0) + coalesce(usd_rmb_profit_ugx, 0)) as exchange_ugx, min(created_at) as first_booked, max(created_at) as last_booked')
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
     * Commission comes from the ledger rather than the transfer rows, so a cancelled transfer
     * takes its commission back here exactly as it does in the wallet, and a hand-posted wallet
     * entry still shows up. Whoever is not an admin sees only their own figures.
     *
     * @return array{rows: \Illuminate\Support\Collection, earned: float, reversed: float, net: float, transfers: int, everyone: bool}
     */
    private function commissionTotals(string $dateFrom, string $dateTo, User $viewer): array
    {
        $everyone = $viewer->can('view-all-wallets');

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

    /**
     * The book for a period in three figures, all of them read in shillings because that is
     * where the money is kept: the charge taken out of the cash handed over, what the rates
     * came to on the money that travelled, and the two summed as the overall profit.
     *
     * A transfer booked before the yuan buying rate was snapshotted has no exchange figure for
     * that leg, so it adds nothing here. Those rows are counted and shown beside the total
     * rather than being guessed at.
     */
    private function profitTotals($completed, float $usdSent): array
    {
        // Read in yuan at the rate each transfer was actually booked at, which is the RMB
        // it delivered divided by the dollars it was worth. Rows saved before those two
        // figures existed fall back to the USD/RMB rate standing on the rate sheet today.
        $fallback = (float) (ExchangeRate::getActiveRate('USD', 'RMB')->rate ?? 0);
        $rate = fn (Transaction $t) => (float) $t->total_usd_equivalent > 0 && (float) $t->rmb_amount > 0
            ? (float) $t->rmb_amount / (float) $t->total_usd_equivalent
            : $fallback;

        $rows = $completed->map(function (Transaction $t) use ($rate) {
            $exchangeUgx = (float) $t->fx_profit_ugx + (float) $t->usd_rmb_profit_ugx;
            $exchangeUsd = (float) $t->fx_profit_usd
                + ($t->usd_rmb_profit_cny === null ? 0.0 : (float) $t->usd_rmb_profit_cny / $rate($t));

            return [
                'fee_ugx' => (float) $t->charge_ugx,
                'fee_usd' => (float) $t->charge_amount,
                'exchange_ugx' => $exchangeUgx,
                'exchange_usd' => $exchangeUsd,
                'total_ugx' => (float) $t->charge_ugx + $exchangeUgx,
                'total_usd' => (float) $t->charge_amount + $exchangeUsd,
                'total_rmb' => ((float) $t->charge_amount + $exchangeUsd) * $rate($t),
            ];
        });

        $sum = fn (string $key) => (float) $rows->sum($key);

        return [
            'fee_ugx' => $sum('fee_ugx'),
            'fee_usd' => $sum('fee_usd'),
            'exchange_ugx' => $sum('exchange_ugx'),
            'exchange_usd' => $sum('exchange_usd'),
            'total_ugx' => $sum('total_ugx'),
            'total_usd' => $sum('total_usd'),
            'total_rmb' => $sum('total_rmb'),
            'per_dollar_ugx' => $usdSent > 0 ? $sum('total_ugx') / $usdSent : 0,
        ];
    }

    public function exportExcel(Request $request)
    {
        abort_if($request->input('type') === 'profit' && !$request->user()->can('view-profit'), 403);
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

        $headers = ['Receipt #', 'Date', 'Sender Name', 'Sender Contact', 'Receiver Name', 'Receiver Contact', 'Currency', 'Amount Sent', 'USD Equivalent', 'Exchange Rate', 'RMB Amount', 'Charge (UGX)', 'Charge (USD)', 'Status', 'Agent', 'Exchange Profit (UGX)', 'Overall Profit (UGX)', 'Overall Profit (USD)'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $col++;
        }
        $sheet->getStyle('A1:R1')->applyFromArray($headerStyle);

        // Profit is only real once the transfer has finished, and a transfer booked before the
        // rates were snapshotted has no exchange figure to add. Those two cases are told apart
        // here: a live transfer that has not finished earns nothing, so it reads 0, while an old
        // row whose prices were never stored reads n/a so nobody mistakes it for break-even.
        $priced = fn (Transaction $t): bool => $t->status === 'completed'
            && $t->usd_rmb_profit_cny !== null
            && (float) ($t->rate_buy_ugx ?? 0) > 0;

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

            if ($t->status !== 'completed') {
                foreach (['P', 'Q', 'R'] as $column) {
                    $sheet->setCellValue($column . $row, 0);
                }
            } elseif (! $priced($t)) {
                foreach (['P', 'Q', 'R'] as $column) {
                    $this->putText($sheet, $column . $row, 'n/a');
                }
            } else {
                $exchangeUgx = (float) $t->fx_profit_ugx + (float) $t->usd_rmb_profit_ugx;
                $sheet->setCellValue('P' . $row, $exchangeUgx);
                $sheet->setCellValue('Q' . $row, (float) $t->charge_ugx + $exchangeUgx);
                $sheet->setCellValue('R' . $row, (float) $t->charge_amount + (float) $t->fx_profit_usd + (float) $t->yuanLegUsd());
            }
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

        if (!$request->user()->can('view-profit')) {
            $sheet->removeColumn('P', 3);
        }
        $filename = 'PlusPay_Report_' . $dateFrom . '_to_' . $dateTo . '.xlsx';
        return response()->streamDownload(
            fn () => (new Xlsx($spreadsheet))->save('php://output'),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    private function putText($sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }
}

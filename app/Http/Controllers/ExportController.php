<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SystemLogReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportController extends Controller
{
    public function transactions(Request $request)
    {
        $rows = $this->transactionQuery($request)
            ->latest()
            ->get()
            ->map(fn($t) => [
                $t->receipt_number,
                $t->created_at->format('Y-m-d h:i A'),
                $t->sender_name,
                $t->sender_contact,
                $t->receiver_name,
                $t->receiver_contact,
                $t->currency . ' ' . number_format((float) $t->amount, 2),
                $this->ugx((float) $t->total_usd_equivalent),
                '¥' . number_format((float) $t->rmb_amount, 2),
                'UGX ' . number_format((float) $t->charge_ugx),
                'USD ' . number_format((float) $t->charge_amount, 2),
                'UGX ' . number_format((float) $t->fx_profit_ugx),
                'UGX ' . number_format((float) $t->charge_ugx + (float) $t->fx_profit_ugx),
                'USD ' . number_format((float) $t->charge_amount + (float) $t->fx_profit_usd, 2),
                $t->purpose ?? '-',
                ucfirst($t->status),
                $t->creator?->name ?? '-',
            ])
            ->all();

        return $this->download('transactions', [
            'Receipt #', 'Date', 'Sender', 'Sender Contact', 'Receiver', 'Receiver Contact',
            'Amount', 'UGX Equivalent', 'RMB', 'Service Fee (UGX)', 'Service Fee (USD)',
            'Dollar Margin (UGX)', 'Total Profit (UGX)', 'Total Profit (USD)', 'Purpose', 'Status', 'Created By',
        ], $rows);
    }

    public function receipts(Request $request)
    {
        $rows = $this->transactionQuery($request)
            ->where('status', 'completed')
            ->latest()
            ->get()
            ->map(fn($t) => [
                $t->receipt_number,
                $t->created_at->format('Y-m-d h:i A'),
                $t->sender_name,
                $t->receiver_name,
                $t->currency . ' ' . number_format((float) $t->amount, 2),
                '¥' . number_format((float) $t->rmb_amount, 2),
                'UGX ' . number_format((float) $t->charge_ugx),
                $t->creator?->name ?? '-',
            ])
            ->all();

        return $this->download('receipts', [
            'Receipt #', 'Date', 'Sender', 'Receiver', 'Amount', 'RMB', 'Charge (UGX)', 'Created By',
        ], $rows);
    }

    public function users(Request $request)
    {
        $rows = User::with('roles')->filtered($request)
            ->latest()
            ->get()
            ->map(fn($u) => [
                $u->name,
                $u->email,
                $u->phone ?? '-',
                RolePermissionController::displayName($u->getRoleNames()->first() ?? ''),
                $u->is_active ? 'Active' : 'Inactive',
                $u->isLocked() ? 'Locked' : 'Unlocked',
                $u->loginWindowSummary(),
                $u->two_factor_enabled ? 'On' : 'Off',
                $u->last_login_at?->format('Y-m-d h:i A') ?? 'Never',
                $u->created_at->format('Y-m-d'),
            ])
            ->all();

        return $this->download('users', [
            'Name', 'Email', 'Phone', 'Role', 'Status', 'Lock', 'Login Hours', 'Two-factor', 'Last Login', 'Added',
        ], $rows);
    }

    public function customers(Request $request)
    {
        $rows = Customer::with('creator')->filtered($request)
            ->latest()
            ->get()
            ->map(fn($c) => [
                $c->name,
                $c->phone,
                ucfirst($c->type),
                $c->country,
                $c->is_active ? 'Active' : 'Inactive',
                $c->notes ?? '-',
                $c->creator?->name ?? '-',
                $c->created_at->format('Y-m-d'),
            ])
            ->all();

        return $this->download('customers', [
            'Name', 'Phone', 'Type', 'Country', 'Status', 'Notes', 'Added By', 'Added',
        ], $rows);
    }

    public function activityLogs(Request $request)
    {
        $rows = ActivityLog::with(['user', 'transaction'])->filtered($request)
            ->latest()
            ->get()
            ->map(fn($log) => [
                $log->created_at->format('Y-m-d h:i A'),
                $log->user?->name ?? 'System',
                $log->action,
                $log->sourceIp() ?? '-',
                $log->request_path ?? '-',
                $log->description ?? '-',
                $log->changeText() ?: '-',
                $log->transaction?->receipt_number ?? '-',
            ])
            ->all();

        return $this->download('activity-logs', [
            'Date', 'User', 'Action', 'IP Address', 'Recorded From', 'Description', 'What Changed', 'Receipt #',
        ], $rows);
    }

    public function loginLogs(Request $request)
    {
        $rows = ActivityLog::with('user')
            ->whereIn('action', ['login', 'logout', 'login_blocked'])
            ->filtered($request)
            ->latest()
            ->get()
            ->map(fn($log) => [
                $log->created_at->format('Y-m-d h:i A'),
                $log->user?->name ?? 'System',
                $log->user?->email ?? '-',
                $log->actionLabel(),
                $log->sourceIp() ?? '-',
                $log->description ?? '-',
            ])
            ->all();

        return $this->download('login-logs', [
            'Date', 'User', 'Email', 'Event', 'IP Address', 'Details',
        ], $rows);
    }

    public function systemLogs(Request $request)
    {
        $meta = SystemLogReader::read($request->input('file'), SystemLogReader::window($request));
        $entries = SystemLogReader::filter($meta['entries'], $request);

        $suffix = str_replace('.log', '', (string) $meta['file']);

        return $this->download(
            'system-logs',
            SystemLogReader::exportHeaders(),
            SystemLogReader::exportRows($entries),
            $suffix
        );
    }

    public function agentPerformance(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->format('Y-m-d'));
        $dateTo = $request->input('date_to', now()->format('Y-m-d'));

        $rows = AgentPerformanceController::report($dateFrom, $dateTo)
            ->map(fn($data) => [
                $data['agent']->name,
                $data['agent']->email,
                number_format($data['total_transactions']),
                number_format($data['completed_transactions']),
                number_format($data['pending_transactions']),
                $data['success_rate'] . '%',
                $this->ugx((float) $data['total_amount']),
                $this->ugx((float) $data['total_revenue']),
            ])
            ->all();

        return $this->download('agent-performance', [
            'Agent', 'Email', 'Transactions', 'Completed', 'Pending', 'Success Rate', 'Amount Sent', 'Revenue Generated',
        ], $rows, "{$dateFrom}_to_{$dateTo}");
    }

    public function exchangeRates(Request $request)
    {
        $rows = ExchangeRate::with(['creator', 'editor'])
            ->filtered($request)
            ->latest()
            ->orderByDesc('id')
            ->get()
            ->map(fn($r) => [
                $r->from_currency,
                $r->to_currency,
                $r->sell_rate ?? $r->rate,
                $r->buy_rate,
                $r->buy_rate === null ? null : (float) ($r->sell_rate ?? $r->rate) - (float) $r->buy_rate,
                $r->is_active ? 'Active' : 'Inactive',
                $r->creator?->name ?? 'Not recorded',
                $r->created_at->format('Y-m-d h:i A'),
                $r->editor?->name ?? 'Not recorded',
                $r->updated_at->format('Y-m-d h:i A'),
            ])
            ->all();

        return $this->download('exchange-rates', [
            'From', 'To', 'Selling Rate', 'Buying Rate', 'Margin', 'Status', 'Added By', 'Added', 'Edited By', 'Updated',
        ], $rows);
    }

    public function accountLedger(Request $request)
    {
        $account = Account::findOrFail((int) $request->input('account_id'));
        $currency = $account->currency;

        $balance = $request->filled('date_from')
            ? $account->balanceBefore($request->date_from)
            : (string) $account->opening_balance;

        $rows = $account->entries()->filtered($request)->get()
            ->map(function (AccountEntry $entry) use (&$balance, $currency) {
                $balance = $entry->direction === 'in'
                    ? bcadd($balance, (string) $entry->amount, 2)
                    : bcsub($balance, (string) $entry->amount, 2);

                return [
                    $entry->entry_date->format('Y-m-d'),
                    AccountEntry::labelFor($entry->type),
                    $entry->direction === 'in' ? number_format((float) $entry->amount, 2) : '',
                    $entry->direction === 'out' ? number_format((float) $entry->amount, 2) : '',
                    $currency,
                    number_format((float) $balance, 2),
                    $entry->counterparty?->name ?? '-',
                    $entry->reference ?? '-',
                    $entry->description,
                    $entry->creator?->name ?? 'System',
                ];
            })
            ->all();

        return $this->download(
            'account-ledger',
            ['Date', 'Movement', 'Money In ('.$currency.')', 'Money Out ('.$currency.')', 'Currency', 'Running Balance ('.$currency.')', 'Other Account', 'Reference', 'Description', 'Entered By'],
            $rows,
            str_replace(' ', '-', $account->name)
        );
    }

    public function dailyBalances(Request $request)
    {
        $from = $request->input('date_from', now()->subDays(13)->toDateString());
        $to = $request->input('date_to', now()->toDateString());
        $accounts = Account::ordered();

        $dates = Account::dateRange($from, $to);

        $closings = [];
        foreach ($accounts as $account) {
            foreach ($account->dailyClosing($from, $dates) as $day => $values) {
                $closings[$day][$account->id] = $values;
            }
        }

        $headers = ['Date'];
        foreach ($accounts as $account) {
            $headers[] = $account->name.' In ('.$account->currency.')';
            $headers[] = $account->name.' Out ('.$account->currency.')';
            $headers[] = $account->name.' Closing ('.$account->currency.')';
        }

        $rows = [];
        foreach ($dates as $day) {
            $row = [$day];
            foreach ($accounts as $account) {
                $values = $closings[$day][$account->id] ?? ['in' => '0.00', 'out' => '0.00', 'closing' => '0.00'];
                $row[] = number_format((float) $values['in'], 2);
                $row[] = number_format((float) $values['out'], 2);
                $row[] = number_format((float) $values['closing'], 2);
            }
            $rows[] = $row;
        }

        return $this->download('daily-balances', $headers, $rows, $from.'-'.$to);
    }

    public function profitReport(Request $request)
    {
        $from = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to = $request->input('date_to', now()->toDateString());

        $days = Transaction::visibleTo($request->user())
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('count(*) as count'),
                DB::raw('sum(total_usd_equivalent) as usd_sent'),
                DB::raw('sum(charge_ugx) as fee_ugx'),
                DB::raw('sum(charge_amount) as fee_usd'),
                DB::raw('sum(fx_profit_ugx) as fx_ugx'),
                DB::raw('sum(fx_profit_usd) as fx_usd'),
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('day')
            ->get();

        $rows = $days->map(fn ($d) => [
            $d->day,
            (string) $d->count,
            number_format((float) $d->usd_sent, 2),
            number_format((float) $d->fee_ugx),
            number_format((float) $d->fee_usd, 2),
            number_format((float) $d->fx_ugx),
            number_format((float) $d->fx_usd, 2),
            number_format((float) $d->fee_ugx + (float) $d->fx_ugx),
            number_format((float) $d->fee_usd + (float) $d->fx_usd, 2),
        ])->all();

        $rows[] = [
            'TOTAL',
            (string) $days->sum('count'),
            number_format((float) $days->sum('usd_sent'), 2),
            number_format((float) $days->sum('fee_ugx')),
            number_format((float) $days->sum('fee_usd'), 2),
            number_format((float) $days->sum('fx_ugx')),
            number_format((float) $days->sum('fx_usd'), 2),
            number_format((float) $days->sum('fee_ugx') + (float) $days->sum('fx_ugx')),
            number_format((float) $days->sum('fee_usd') + (float) $days->sum('fx_usd'), 2),
        ];

        return $this->download('profit-report', [
            'Date', 'Transactions', 'USD Sent', 'Fee Income (UGX)', 'Fee Income (USD)',
            'Dollar Margin (UGX)', 'Dollar Margin (USD)', 'Total Profit (UGX)', 'Total Profit (USD)',
        ], $rows, $from . '-' . $to);
    }

    private function transactionQuery(Request $request)
    {
        return Transaction::with('creator')
            ->visibleTo($request->user())
            ->filtered($request);
    }

    private function ugx(float $usd): string
    {
        $rate = ExchangeRate::usdToUgx();

        return $rate
            ? 'UGX ' . number_format($usd * $rate)
            : 'USD ' . number_format($usd, 2);
    }

    private function download(string $name, array $headers, array $rows, string $suffix = '')
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'exported',
            'description' => 'Exported ' . $name . ' (' . count($rows) . ' row(s))',
            'new_value' => (string) count($rows),
        ]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $report = ucwords(str_replace('-', ' ', $name));
        $sheet->setTitle($report);

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $headerRow = 5;
        $firstDataRow = $headerRow + 1;
        $lastDataRow = $firstDataRow + count($rows) - 1;

        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', 'PlusPay');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '14532D']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(26);

        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->setCellValue('A2', $report);
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => '14532D'], 'size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);

        $sheet->mergeCells("A3:{$lastColumn}3");
        $sheet->setCellValue('A3', 'Plus Save Financial Solutions  |  Generated ' . now()->format('M d, Y h:i A') . '  |  ' . count($rows) . ' record(s)');
        $sheet->getStyle('A3')->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => '6B7280'], 'size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);

        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . $headerRow, $header);
        }

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '14532D']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $row = $firstDataRow;
        foreach ($rows as $values) {
            foreach (array_values($values) as $index => $value) {
                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($index + 1) . $row,
                    (string) $value,
                    DataType::TYPE_STRING
                );
            }
            $row++;
        }

        if (count($rows) > 0) {
            $sheet->getStyle("A{$firstDataRow}:{$lastColumn}{$lastDataRow}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);

            $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastDataRow}");
        }

        $sheet->freezePane("A{$firstDataRow}");

        foreach (range(1, count($headers)) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }

        $filename = 'PlusPay_' . str_replace('-', '_', $name) . ($suffix ? '_' . $suffix : '')
            . '_' . now()->format('Ymd-His') . '.xlsx';

        return response()->streamDownload(
            fn() => (new Xlsx($spreadsheet))->save('php://output'),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }
}

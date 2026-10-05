<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
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
                $t->created_at->format('Y-m-d H:i'),
                $t->sender_name,
                $t->sender_contact,
                $t->receiver_name,
                $t->receiver_contact,
                $t->currency . ' ' . number_format((float) $t->amount, 2),
                $this->ugx((float) $t->total_usd_equivalent),
                '¥' . number_format((float) $t->rmb_amount, 2),
                'UGX ' . number_format((float) $t->charge_ugx),
                'USD ' . number_format((float) $t->charge_amount, 2),
                $t->purpose ?? '-',
                ucfirst($t->status),
                $t->creator?->name ?? '-',
            ])
            ->all();

        return $this->download('transactions', [
            'Receipt #', 'Date', 'Sender', 'Sender Contact', 'Receiver', 'Receiver Contact',
            'Amount', 'UGX Equivalent', 'RMB', 'Service Fee (UGX)', 'Service Fee (USD)', 'Purpose', 'Status', 'Created By',
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
                $t->created_at->format('Y-m-d H:i'),
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
                $u->last_login_at?->format('Y-m-d H:i') ?? 'Never',
                $u->created_at->format('Y-m-d'),
            ])
            ->all();

        return $this->download('users', [
            'Name', 'Email', 'Phone', 'Role', 'Status', 'Lock', 'Last Login', 'Added',
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
                $log->created_at->format('Y-m-d H:i'),
                $log->user?->name ?? 'System',
                $log->action,
                $log->description ?? '-',
                $log->transaction?->receipt_number ?? '-',
            ])
            ->all();

        return $this->download('activity-logs', [
            'Date', 'User', 'Action', 'Description', 'Receipt #',
        ], $rows);
    }

    public function loginLogs(Request $request)
    {
        $rows = ActivityLog::with('user')
            ->whereIn('action', ['login', 'logout'])
            ->filtered($request)
            ->latest()
            ->get()
            ->map(fn($log) => [
                $log->created_at->format('Y-m-d H:i'),
                $log->user?->name ?? 'System',
                $log->user?->email ?? '-',
                ucfirst($log->action),
                $log->description ?? '-',
            ])
            ->all();

        return $this->download('login-logs', [
            'Date', 'User', 'Email', 'Event', 'Details',
        ], $rows);
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
        $rows = ExchangeRate::filtered($request)
            ->orderBy('from_currency')
            ->orderBy('to_currency')
            ->get()
            ->map(fn($r) => [
                $r->from_currency,
                $r->to_currency,
                $r->rate,
                $r->is_active ? 'Active' : 'Inactive',
                $r->updated_at->format('Y-m-d H:i'),
            ])
            ->all();

        return $this->download('exchange-rates', [
            'From', 'To', 'Rate', 'Status', 'Updated',
        ], $rows);
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
        $sheet->setTitle(ucfirst(str_replace('-', ' ', $name)));

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));

        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . '1', $header);
        }

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '14532D']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $row = 2;
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

        if ($row > 2) {
            $sheet->getStyle("A2:{$lastColumn}" . ($row - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
        }

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

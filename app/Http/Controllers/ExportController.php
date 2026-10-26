<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ActivityLog;
use App\Models\CurrencyExchange;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExchangeRate;
use App\Models\LoginLog;
use App\Models\SystemLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SystemLogReader;
use App\Support\WalletHistory;
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
            ->map(function ($t) {
                // One profit figure per transfer, in shillings because that is where the money
                // is kept: what the rates came to on the amount that travelled, then the same
                // added to the charge as the overall profit.
                //
                // A transfer that has not finished earned nothing yet, and one booked before
                // the rates were snapshotted has no exchange figure to add. Those two states are
                // kept apart here exactly as on the screen: 0.0 for the first, n/a for the
                // second, and never a zero that reads as break-even.
                $labels = $t->profitLabels();
                $counts = $t->status === 'completed';
                $yuanUsd = $counts ? $t->yuanLegUsd() : 0.0;
                $totalUsd = $yuanUsd === null
                    ? null
                    : (float) $t->charge_amount + (float) $t->fx_profit_usd + (float) $yuanUsd;

                $signed = function (?float $value, int $decimals, string $prefix) use ($counts) {
                    if (! $counts) {
                        return $prefix . number_format(0, $decimals);
                    }

                    if ($value === null) {
                        return 'n/a';
                    }

                    return ($value < 0 ? 'Loss ' : '') . $prefix . number_format(abs($value), $decimals);
                };

                return [
                    $t->receipt_number,
                    $t->created_at->format('Y-m-d h:i A'),
                    $t->sender_name,
                    $t->sender_contact,
                    $t->receiver_name,
                    $t->receiver_contact,
                    $t->collectedAmount(),
                    $t->principalLabel(),
                    $this->ugx((float) $t->total_usd_equivalent),
                    '¥' . number_format((float) $t->rmb_amount, 1),
                    'UGX ' . number_format((float) $t->charge_ugx),
                    'USD ' . number_format((float) $t->charge_amount, 1),
                    $this->rate($t->rate_sell_ugx),
                    $this->rate($t->exchange_rate),
                    $labels['exchange']['text'],
                    $labels['total']['text'],
                    $signed($totalUsd, 1, 'USD '),
                    $t->purpose ?? '-',
                    $t->payment_method ?? '-',
                    $t->payment_reference ?? '-',
                    ucfirst($t->status),
                    $this->bookedBy($t),
                    $t->deskLabel(),
                    $t->originLabel().' to '.$t->destinationLabel(),
                    $t->settled_at?->format('Y-m-d h:i A') ?? '-',
                    $t->settledDesk?->name ?? '-',
                    $t->delivered_at?->format('Y-m-d h:i A') ?? '-',
                ];
            })
            ->all();

        $headers = [
            'Receipt #', 'Date', 'Sender', 'Sender Contact', 'Receiver', 'Receiver Contact',
            'Money Handed Over', 'Amount Sent', 'UGX Equivalent', 'RMB', 'Charge (UGX)', 'Charge (USD)',
            'Dollar Selling Price (per USD)', 'Yuan Selling Price (per USD)',
            'Exchange Profit (UGX)', 'Overall Profit (UGX)', 'Overall Profit (USD)',
            'Reason', 'Paid How', 'Confirmation Number', 'Status', 'Booked By', 'Desk', 'Route',
            // The last three columns are the settlement chain: a row with '-' under all of them is
            // still cash in a hand in Uganda.
            'Sent To China', 'Held In China By', 'Paid In China',
        ];
        if (!$request->user()->can('view-profit')) {
            // The two selling prices and the three profit figures are the desk's own numbers,
            // so a teller's download keeps the customer's side of the row and nothing else.
            array_splice($headers, 12, 5);
            $rows = array_map(function ($row) { array_splice($row, 12, 5); return $row; }, $rows);
        }
        return $this->download('transactions', $headers, $rows);
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
                $t->principalLabel(),
                '¥' . number_format((float) $t->rmb_amount, 1),
                'UGX ' . number_format((float) $t->charge_ugx),
                $this->bookedBy($t),
                $t->deskLabel(),
            ])
            ->all();

        return $this->download('receipts', [
            'Receipt #', 'Date', 'Sender', 'Receiver', 'Amount', 'RMB', 'Charge (UGX)', 'Booked By', 'Desk',
        ], $rows);
    }

    /**
     * The teller who booked a transfer. Their desk is its own column now, read off the transfer
     * rather than off the person, so a download taken today says where the money was actually
     * taken rather than where that person happens to sit today.
     */
    private function bookedBy(Transaction $t): string
    {
        return $t->creator?->name ?? '-';
    }

    public function users(Request $request)
    {
        $rows = User::with(['roles', 'creator', 'editor'])->filtered($request)
            ->latest()
            ->get()
            ->map(fn($u) => [
                $u->name,
                $u->email,
                $u->phone ?? '-',
                RolePermissionController::displayName($u->getRoleNames()->first() ?? ''),
                $u->countryLabel() ?: '-',
                $u->is_active ? 'Active' : 'Inactive',
                $u->isLocked() ? 'Locked' : 'Unlocked',
                $u->loginWindowSummary(),
                $u->two_factor_enabled ? 'On' : 'Off',
                $u->last_login_at?->format('Y-m-d h:i A') ?? 'Never',
                $u->creator?->name ?? '-',
                $u->editor?->name ?? '-',
                $u->created_at->format('Y-m-d'),
            ])
            ->all();

        return $this->download('users', [
            'Name', 'Email', 'Phone', 'Role', 'Country', 'Status', 'Lock', 'Login Hours', 'Two-factor', 'Last Login', 'Added By', 'Edited By', 'Added',
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
            ->map(fn($log) => $this->activityLogRow($log))
            ->all();

        return $this->download('activity-logs', [
            'Date', 'User', 'Action', 'IP Address', 'Recorded From', 'Description', 'What Changed', 'Receipt #',
        ], $rows, '', true);
    }

    /**
     * The rows ticked on the page, exported as their own file so a selection can be
     * pulled out without exporting the whole trail.
     */
    public function activityLogsSelected(Request $request)
    {
        $ids = collect(explode(',', (string) $request->input('ids', '')))
            ->map(fn ($id) => trim($id))
            ->filter(fn ($id) => ctype_digit($id))
            ->take(500)
            ->all();

        $rows = ActivityLog::with(['user', 'transaction'])
            ->whereIn('id', $ids)
            ->latest()
            ->get()
            ->map(fn($log) => $this->activityLogRow($log))
            ->all();

        return $this->download('activity-logs-selected', [
            'Date', 'User', 'Action', 'IP Address', 'Recorded From', 'Description', 'What Changed', 'Receipt #',
        ], $rows, '', true);
    }

    private function activityLogRow(ActivityLog $log): array
    {
        return [
            $log->created_at->format('Y-m-d h:i A'),
            $log->user?->name ?? 'System',
            $log->action,
            $log->sourceIp() ?? '-',
            $log->request_path ?? '-',
            $log->description ?? '-',
            $log->changeText() ?: '-',
            $log->transaction?->receipt_number ?? '-',
        ];
    }

    public function loginLogs(Request $request)
    {
        $rows = LoginLog::with('user')->filtered($request)
            ->latest()
            ->get()
            ->map(fn($log) => [
                $log->created_at->format('Y-m-d h:i A'),
                $log->user?->name ?? ($log->email_attempted ?: 'No such account'),
                $log->email_attempted ?? '-',
                $log->statusLabel(),
                $log->ip_address ?? '-',
                $log->device ?? '-',
                $log->browser ?? '-',
            ])
            ->all();

        return $this->download('login-logs', [
            'Date', 'User', 'Email Attempted', 'Status', 'IP Address', 'Device', 'Browser',
        ], $rows, '', true);
    }

    public function systemLogs(Request $request)
    {
        $rows = SystemLog::filtered($request)
            ->latest()
            ->get()
            ->map(fn($log) => [
                $log->created_at->format('Y-m-d h:i A'),
                strtoupper($log->level),
                mb_substr(preg_replace('/\s+/', ' ', $log->message), 0, 500),
                $log->exception_class ?? '-',
                $log->file ? $log->file . ($log->line ? ':' . $log->line : '') : '-',
                $log->url ?? '-',
                $log->resolved ? 'Resolved' : 'Open',
            ])
            ->all();

        return $this->download('system-logs', [
            'Date', 'Level', 'Message', 'Class', 'Where', 'URL', 'Status',
        ], $rows, '', true);
    }

    public function agentPerformance(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->format('Y-m-d'));
        $dateTo = $request->input('date_to', now()->format('Y-m-d'));

        $rows = AgentPerformanceController::report($dateFrom, $dateTo)
            ->map(fn($data) => [
                $data['agent']->name,
                $data['agent']->email,
                $data['role'],
                number_format($data['total_transactions']),
                number_format($data['completed_transactions']),
                number_format($data['pending_transactions']),
                $data['success_rate'] . '%',
                $this->ugx((float) $data['sent_ugx']),
                $this->ugx((float) $data['revenue_ugx']),
            ])
            ->all();

        return $this->download('agent-performance', [
            'Agent', 'Email', 'Role', 'Transactions', 'Completed', 'Pending', 'Success Rate', 'Sent (UGX)', 'Revenue (UGX)',
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

    /**
     * The bureau book: every counter deal with both sides written out, in the currencies they
     * actually changed hands in. Only the margin column adds up across rows because the two sides
     * of one deal are never the same currency.
     */
    public function exchanges(Request $request)
    {
        $rows = CurrencyExchange::with(['inAccount', 'outAccount', 'customer', 'creator'])
            ->visibleTo($request->user())
            ->filtered($request)
            ->get()
            ->sortByDesc('id')
            ->sortByDesc('exchange_date')
            ->map(fn (CurrencyExchange $x) => [
                $x->exchange_date->format('Y-m-d'),
                $x->reference,
                $x->person_name,
                $x->customer?->phone ?? '-',
                $x->isVoid() ? 'Cancelled' : 'Active',
                Account::format($x->in_currency, $x->in_amount),
                Account::format($x->out_currency, $x->out_amount),
                $x->quotedRate() ?? '-',
                $x->margin_ugx === null ? 'no rate card' : 'UGX '.number_format((float) $x->margin_ugx, 2),
                $x->inAccount?->name ?? '-',
                $x->outAccount?->name ?? '-',
                $x->notes ?? '-',
                $x->creator?->name ?? 'System',
            ])
            ->values()
            ->all();

        $headers = [
            'Date', 'Reference', 'Person', 'Their Phone', 'Status', 'Taken In', 'Handed Over',
            'Rate Done At', 'Margin (UGX)', 'Money Went Into', 'Money Came From', 'Notes', 'Entered By',
        ];
        if (!$request->user()->can('view-profit')) {
            array_splice($headers, 8, 1);
            $rows = array_map(function ($row) { array_splice($row, 8, 1); return $row; }, $rows);
        }
        return $this->download('exchanges', $headers, $rows);
    }

    public function expenses(Request $request)
    {
        $viewer = $request->user();

        $query = Expense::with(['account', 'creator', 'categoryGroup'])->filtered($request);

        // The screen shows a desk only its own entries unless it may read the whole spending book,
        // and the download has to be the same page in a file rather than the whole book by id.
        if (! $viewer->can('view-all-expenses')) {
            $query->where('created_by', $viewer->id);
        }

        $rows = $query
            ->get()
            ->sortByDesc('id')
            ->sortByDesc('expense_date')
            ->map(fn (Expense $e) => [
                $e->expense_date->format('Y-m-d'),
                $e->reference,
                $e->category_name,
                $e->description,
                Account::format($e->currency, $e->amount),
                $e->account?->name ?? '-',
                $e->paid_via ?? '-',
                $e->isVoid() ? 'Cancelled' : 'Active',
                $e->notes ?? '-',
                $e->creator?->name ?? 'System',
            ])
            ->values()
            ->all();

        return $this->download('expenses', [
            'Date', 'Reference', 'Spent On', 'Details', 'Amount', 'Paid Out Of', 'Paid How',
            'Status', 'Notes', 'Entered By',
        ], $rows);
    }

    /**
     * All the cash books on one sheet, with the account that went up named on the same line as the
     * one that came down so a movement can be checked without opening four ledgers.
     */
    public function movements(Request $request)
    {
        $query = AccountEntry::with(['account', 'counterparty', 'creator', 'transaction'])->filtered($request);

        if ($request->filled('account_id')) {
            $query->where('account_id', (int) $request->input('account_id'));
        }

        $rows = $query->orderByDesc('entry_date')->orderByDesc('id')->get()
            ->map(function (AccountEntry $entry) {
                $account = $entry->account;
                $format = fn (?string $currency, $value) => Account::format($currency, $value);

                return [
                    $entry->entry_date->format('Y-m-d'),
                    $account?->name ?? 'Deleted account',
                    AccountEntry::labelFor($entry->type),
                    $entry->direction === 'in' ? $format($account?->currency, $entry->amount) : '',
                    $entry->direction === 'out' ? $format($account?->currency, $entry->amount) : '',
                    $entry->counterparty?->name ?? 'Outside the business',
                    $entry->transaction?->receipt_number ?? '-',
                    $entry->reference ?? '-',
                    $entry->description,
                    $entry->creator?->name ?? 'System',
                ];
            })
            ->all();

        return $this->download('movements', [
            'Date', 'Account', 'Movement', 'Goes Up', 'Goes Down', 'Against', 'Transfer',
            'Reference', 'Description', 'Entered By',
        ], $rows);
    }

    /**
     * The drawer's own book, exactly as the wallet page lists it after the filters on the screen
     * are applied, running balance included. A person downloads their own cash history, so the
     * gate is the same one that opens the page.
     */
    public function cashHistory(Request $request)
    {
        $rows = collect(WalletHistory::filtered(WalletHistory::lines($request->user()), $request))
            ->map(fn (array $line) => [
                $line['date']->format('Y-m-d').' '.($line['clock']?->format('H:i') ?? ''),
                $line['currency'],
                $line['label'],
                $line['reference'] ?? '-',
                $line['status'] ? ucfirst($line['status']) : '',
                $line['in'] !== null ? Account::format($line['currency'], $line['in']) : '',
                $line['out'] !== null ? Account::format($line['currency'], $line['out']) : '',
                Account::format($line['currency'], $line['running']),
                $line['description'],
            ])
            ->all();

        return $this->download('cash-history', [
            'Date', 'Wallet', 'What happened', 'Reference', 'Transfer', 'In', 'Out', 'Left in hand', 'Description',
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

        // Each day's yuan figures price that day's own transfers at the rate they were booked
        // at, the same rule the profit cards and the reports tables use.
        [$rate, $binding] = Transaction::yuanRateSql();

        // A transfer booked before its rates were stored has no exchange figure at all, so its
        // parts add up to less than the transfer really earned. Those rows are counted here so a
        // day line can be read as incomplete instead of being taken for the true figure.
        $days = Transaction::visibleTo($request->user())
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw(implode(', ', [
                'DATE(created_at) as day',
                'count(*) as count',
                'sum(total_usd_equivalent) as usd_sent',
                'sum(charge_ugx) as fee_ugx',
                'sum(coalesce(fx_profit_ugx, 0) + coalesce(usd_rmb_profit_ugx, 0)) as exchange_ugx',
                'sum(charge_ugx + coalesce(fx_profit_ugx, 0) + coalesce(usd_rmb_profit_ugx, 0)) as total_ugx',
                'sum(coalesce(charge_amount, 0) + coalesce(fx_profit_usd, 0) + coalesce(usd_rmb_profit_cny, 0) / nullif(' . $rate . ', 0)) as total_usd',
                'sum(coalesce(charge_amount, 0) * (' . $rate . ') + coalesce(fx_profit_usd, 0) * (' . $rate . ') + coalesce(usd_rmb_profit_cny, 0)) as total_rmb',
                'sum(case when usd_rmb_profit_cny is null or coalesce(rate_buy_ugx, 0) <= 0 then 1 else 0 end) as unpriced',
            ]), array_merge($binding, $binding, $binding))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('day')
            ->get();

        $line = fn ($row) => array_merge(
            [(string) $row->count, number_format((float) $row->usd_sent, 1)],
            [number_format((float) $row->fee_ugx), number_format((float) $row->exchange_ugx, 1)],
            $this->money($row->total_ugx, $row->total_usd, $row->total_rmb),
            [(string) $row->unpriced]
        );

        $rows = $days->map(fn ($d) => array_merge([$d->day], $line($d)))->all();

        $totals = (object) [
            'count' => $days->sum('count'),
            'usd_sent' => $days->sum('usd_sent'),
            'fee_ugx' => $days->sum('fee_ugx'),
            'exchange_ugx' => $days->sum('exchange_ugx'),
            'total_ugx' => $days->sum('total_ugx'),
            'total_usd' => $days->sum('total_usd'),
            'total_rmb' => $days->sum('total_rmb'),
            'unpriced' => $days->sum('unpriced'),
        ];

        $rows[] = array_merge(['TOTAL'], $line($totals));

        return $this->download('profit-report', [
            'Date', 'Transactions', 'USD Sent', 'Charge (UGX)', 'Exchange Profit (UGX)',
            'Overall Profit (UGX)', 'Overall Profit (USD)', 'Overall Profit (RMB)',
            'Transfers With No Rates',
        ], $rows, $from . '-' . $to);
    }

    /**
     * One profit group written in the three currencies the book is kept in, shillings first.
     * A negative figure is the minus sign's job, so the cells stay plain numbers to sum.
     */
    private function money($ugx, $usd, $rmb): array
    {
        return [
            number_format((float) $ugx, 1),
            number_format((float) $usd, 1),
            number_format((float) $rmb, 1),
        ];
    }

    private function transactionQuery(Request $request)
    {
        return Transaction::with(['creator', 'settledDesk'])
            ->visibleTo($request->user())
            ->filtered($request);
    }

    /**
     * A booked price as the accountant reads it: exact to four places with the trailing zeros
     * cut, and n/a where a transfer predates the prices being stored.
     */
    private function rate(?string $price): string
    {
        return $price === null || (float) $price === 0.0
            ? 'n/a'
            : rtrim(rtrim(number_format((float) $price, 4, '.', ''), '0'), '.');
    }

    private function ugx(float $usd): string
    {
        $rate = ExchangeRate::usdToUgx();

        return $rate
            ? 'UGX ' . number_format($usd * $rate)
            : 'USD ' . number_format($usd, 1);
    }

    private function download(string $name, array $headers, array $rows, string $suffix = '', bool $withTotal = false)
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
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
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

        if ($withTotal) {
            $totalRow = $firstDataRow + count($rows);
            $sheet->setCellValue("A{$totalRow}", 'Total records');
            $sheet->setCellValueExplicit("B{$totalRow}", (string) count($rows), DataType::TYPE_STRING);
            $sheet->getStyle("A{$totalRow}:B{$totalRow}")->applyFromArray([
                'font' => ['bold' => true],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
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

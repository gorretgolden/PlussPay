<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ExchangeRate;
use App\Models\LoginLog;
use App\Models\SystemLog;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $role = $user->getRoleNames()->first() ?? 'User';
        $usdToUgx = $this->usdToUgxRate();

        ['sent' => $ugxSent, 'fee' => $ugxRevenue] = $this->ugxTotals($usdToUgx);

        $stats = Transaction::query()
            ->visibleTo($user)
            ->selectRaw("
                COUNT(*) as total_transactions,
                COALESCE(SUM(CASE WHEN status='completed' THEN total_usd_equivalent ELSE 0 END), 0) as total_usd_sent,
                COALESCE(SUM(CASE WHEN status='completed' THEN charge_amount ELSE 0 END), 0) as total_revenue,
                COALESCE(SUM(CASE WHEN status='completed' THEN rmb_amount ELSE 0 END), 0) as total_rmb_sent,
                {$ugxSent} as total_ugx_sent,
                {$ugxRevenue} as total_revenue_ugx,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending_transactions,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed_transactions,
                SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) as cancelled_transactions,
                COALESCE(AVG(CASE WHEN status='completed' THEN total_usd_equivalent ELSE NULL END), 0) as avg_transaction
            ")
            ->toBase()
            ->first();

        $todayStats = Transaction::query()
            ->visibleTo($user)
            ->whereDate('created_at', today())
            ->selectRaw("
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as today_transactions,
                COALESCE(SUM(CASE WHEN status='completed' THEN total_usd_equivalent ELSE 0 END), 0) as today_usd_sent,
                COALESCE(SUM(CASE WHEN status='completed' THEN charge_amount ELSE 0 END), 0) as today_revenue,
                {$ugxSent} as today_ugx_sent,
                {$ugxRevenue} as today_revenue_ugx
            ")
            ->toBase()
            ->first();

        $stats = (array) $stats + (array) $todayStats;

        // Derived from the UGX already summed above, so the average cannot disagree
        // with the Money Sent card the way an average of re-converted USD did.
        $stats['avg_ugx_sent'] = $stats['completed_transactions'] > 0
            ? $stats['total_ugx_sent'] / $stats['completed_transactions']
            : 0;

        $stats['total_users'] = $user->hasRole(['admin', 'superadmin']) ? User::where('is_active', true)->count() : 0;
        $stats['total_agents'] = $user->hasRole(['admin', 'superadmin']) ? User::role('agent')->where('is_active', true)->count() : 0;
        $stats['active_rates'] = $user->hasRole(['admin', 'superadmin']) ? ExchangeRate::where('is_active', true)->count() : 0;

        $recentTransactions = Transaction::query()
            ->visibleTo($user)
            ->latest()
            ->take(10)
            ->get();

        $chartData = $this->buildChartData($user, $usdToUgx);

        // One chart per role. Whoever owns the book gets the charge and the exchange figures
        // stacked into the overall profit; everyone else only sees the charge they earned. Both
        // series are always in shillings, which is where the money is kept.
        $seesProfit = $user->hasRole(['admin', 'superadmin', 'accountant']);

        if (! $seesProfit) {
            unset($chartData['exchange_ugx']);
        }

        $profit = $seesProfit ? $this->profitWindows($user, $usdToUgx) : [];

        $wallets = Account::walletsFor($user);

        // Commission only ever lands in a shilling wallet, whoever booked the transfer, so it is
        // read from that account directly rather than from the wallets this person is shown.
        // A desk in China is shown yuan and dollars but still earns its commission in shillings.
        $commissionMonth = (float) AccountEntry::query()
            ->where('account_id', Account::walletFor($user, 'UGX')->id)
            ->whereIn('type', ['agent_fee', 'agent_fee_reversal'])
            ->whereDate('entry_date', '>=', now()->startOfMonth()->toDateString())
            ->whereDate('entry_date', '<=', now()->toDateString())
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'agent_fee' THEN amount ELSE -amount END), 0) as net")
            ->value('net');

        // Admins manage people, so a spike of failed sign-ins belongs on the page they open
        // first; only the Super Admin sees the machine errors, matching the Logs tabs.
        $logAlerts = [];
        if ($user->hasRole(['admin', 'superadmin'])) {
            try {
                $logAlerts['failed_logins_24h'] = LoginLog::where('status', 'failed')
                    ->where('created_at', '>=', now()->subDay())
                    ->count();
                if ($user->hasRole('superadmin')) {
                    $logAlerts['unresolved_errors'] = SystemLog::where('resolved', false)->count();
                }
            } catch (\Throwable) {
                // login_logs / system_logs not migrated yet; show no badges.
            }
        }

        return view('dashboard', compact('stats', 'recentTransactions', 'chartData', 'role', 'usdToUgx', 'wallets', 'commissionMonth', 'profit', 'logAlerts'));
    }

    private function usdToUgxRate(): ?float
    {
        return ExchangeRate::usdToUgx();
    }

    /**
     * UGX as each transfer was actually priced, the same way Reports reads it.
     *
     * Converting the stored dollars at today's rate is what made the dashboard drift from
     * Reports whenever the rate moved. exchange_rate is the dollar to RMB rate, so the UGX
     * price a dollar-funded transfer was sold at lives in rate_sell_ugx; rows booked before
     * that column was filled fall back to today's selling rate.
     *
     * @return array{sent: string, fee: string}
     */
    private function ugxTotals(?float $usdToUgx): array
    {
        $today = number_format((float) ($usdToUgx ?? 0), 4, '.', '');

        $principal = "(CASE WHEN currency = 'UGX' THEN amount"
            . " ELSE total_usd_equivalent * COALESCE(NULLIF(rate_sell_ugx, 0), {$today}) END)";
        $fee = "(CASE WHEN charge_ugx > 0 THEN charge_ugx"
            . " ELSE charge_amount * COALESCE(NULLIF(rate_sell_ugx, 0), {$today}) END)";

        return [
            'sent' => "COALESCE(SUM(CASE WHEN status = 'completed' THEN {$principal} ELSE 0 END), 0)",
            'fee' => "COALESCE(SUM(CASE WHEN status = 'completed' THEN {$fee} ELSE 0 END), 0)",
        ];
    }

    private function buildChartData(User $user, ?float $usdToUgx): array
    {
        $startDate = now()->subDays(6)->startOfDay();
        $ugxRevenue = $this->ugxTotals($usdToUgx)['fee'];
        $exchange = $this->exchangeProfitSql();

        $daily = Transaction::query()
            ->visibleTo($user)
            ->where('created_at', '>=', $startDate)
            ->selectRaw("DATE(created_at) as date, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as transactions, COALESCE(SUM(CASE WHEN status='completed' THEN charge_amount ELSE 0 END), 0) as revenue, {$ugxRevenue} as revenue_ugx, COALESCE(SUM(CASE WHEN status='completed' THEN {$exchange} ELSE 0 END), 0) as exchange_ugx")
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get()
            ->keyBy('date');

        $labels = [];
        $transactions = [];
        $revenue = [];
        $revenueUgx = [];
        $exchangeUgx = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $labels[] = now()->subDays($i)->format('M d');
            $transactions[] = (int) ($daily[$date]->transactions ?? 0);
            $revenue[] = (float) ($daily[$date]->revenue ?? 0);
            $revenueUgx[] = (float) ($daily[$date]->revenue_ugx ?? 0);
            $exchangeUgx[] = (float) ($daily[$date]->exchange_ugx ?? 0);
        }

        return [
            'labels' => $labels,
            'transactions' => $transactions,
            'revenue' => $revenue,
            'revenue_ugx' => $revenueUgx,
            'exchange_ugx' => $exchangeUgx,
        ];
    }

    /**
     * What the three profit figures come to over a stretch of time, in shillings as each
     * transfer was priced rather than re-converted at today's rate, so the dashboard cannot
     * drift from Reports when the rate moves.
     *
     * The yuan leg is missing on transfers booked before the rates were snapshotted, so those
     * are counted and reported as unpriced instead of being quietly added as zero.
     *
     * @return array<string, array{fees: float, exchange: float, total: float, unpriced: int}>
     */
    private function profitWindows(User $user, ?float $usdToUgx): array
    {
        $fee = $this->ugxTotals($usdToUgx)['fee'];
        $exchange = $this->exchangeProfitSql();

        $windows = [
            'today' => fn (Builder $q) => $q->whereDate('created_at', today()),
            'month' => fn (Builder $q) => $q->whereBetween('created_at', [now()->startOfMonth(), now()]),
            'all' => fn (Builder $q) => $q,
        ];

        $sums = [];

        foreach ($windows as $window => $restrict) {
            // ugxTotals() hands back a finished aggregate, while the exchange expression is
            // per row and has to be summed here.
            $row = (array) $restrict(Transaction::query()->visibleTo($user))
                ->selectRaw("{$fee} as fees")
                ->selectRaw("COALESCE(SUM(CASE WHEN status = 'completed' THEN {$exchange} ELSE 0 END), 0) as exchange")
                ->selectRaw("SUM(CASE WHEN status = 'completed' AND (COALESCE(rate_buy_ugx, 0) <= 0 OR usd_rmb_profit_cny IS NULL) THEN 1 ELSE 0 END) as unpriced")
                ->toBase()
                ->first();

            $sums[$window] = [
                'fees' => (float) ($row['fees'] ?? 0),
                'exchange' => (float) ($row['exchange'] ?? 0),
                'total' => (float) ($row['fees'] ?? 0) + (float) ($row['exchange'] ?? 0),
                'unpriced' => (int) ($row['unpriced'] ?? 0),
            ];
        }

        return $sums;
    }

    /**
     * Both currency legs together: the spread taken on the shillings and the spread paid or
     * earned turning the dollars into yuan. A transfer that was never priced contributes
     * nothing here, which is why the unpriced count travels alongside it.
     */
    private function exchangeProfitSql(): string
    {
        return "COALESCE(fx_profit_ugx, 0) + COALESCE(usd_rmb_profit_ugx, 0)";
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
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
        $stats['active_rates'] = $user->hasRole(['admin', 'superadmin']) ? ExchangeRate::where('is_active', true)->count() : 0;

        $recentTransactions = Transaction::query()
            ->visibleTo($user)
            ->latest()
            ->take(10)
            ->get();

        $chartData = $this->buildChartData($user, $usdToUgx);

        if ($usdToUgx) {
            $chartData['revenue'] = $chartData['revenue_ugx'];
        }
        unset($chartData['revenue_ugx']);

        $wallets = Account::walletsFor($user);

        return view('dashboard', compact('stats', 'recentTransactions', 'chartData', 'role', 'usdToUgx', 'wallets'));
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

        $daily = Transaction::query()
            ->visibleTo($user)
            ->where('created_at', '>=', $startDate)
            ->selectRaw("DATE(created_at) as date, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as transactions, COALESCE(SUM(CASE WHEN status='completed' THEN charge_amount ELSE 0 END), 0) as revenue, {$ugxRevenue} as revenue_ugx")
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get()
            ->keyBy('date');

        $labels = [];
        $transactions = [];
        $revenue = [];
        $revenueUgx = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $labels[] = now()->subDays($i)->format('M d');
            $transactions[] = (int) ($daily[$date]->transactions ?? 0);
            $revenue[] = (float) ($daily[$date]->revenue ?? 0);
            $revenueUgx[] = (float) ($daily[$date]->revenue_ugx ?? 0);
        }

        return ['labels' => $labels, 'transactions' => $transactions, 'revenue' => $revenue, 'revenue_ugx' => $revenueUgx];
    }
}

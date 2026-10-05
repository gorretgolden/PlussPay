<?php

namespace App\Http\Controllers;

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

        $scope = $user->hasRole('agent') ? $user->id : null;

        $stats = DB::table('transactions')
            ->when($scope, fn($q) => $q->where('created_by', $scope))
            ->selectRaw("
                COUNT(*) as total_transactions,
                COALESCE(SUM(CASE WHEN status='completed' THEN total_usd_equivalent ELSE 0 END), 0) as total_usd_sent,
                COALESCE(SUM(CASE WHEN status='completed' THEN charge_amount ELSE 0 END), 0) as total_revenue,
                COALESCE(SUM(CASE WHEN status='completed' THEN rmb_amount ELSE 0 END), 0) as total_rmb_sent,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending_transactions,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed_transactions,
                SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) as cancelled_transactions,
                COALESCE(AVG(CASE WHEN status='completed' THEN total_usd_equivalent ELSE NULL END), 0) as avg_transaction
            ")
            ->first();

        $todayStats = DB::table('transactions')
            ->when($scope, fn($q) => $q->where('created_by', $scope))
            ->whereDate('created_at', today())
            ->selectRaw("
                COUNT(*) as today_transactions,
                COALESCE(SUM(CASE WHEN status='completed' THEN total_usd_equivalent ELSE 0 END), 0) as today_usd_sent,
                COALESCE(SUM(CASE WHEN status='completed' THEN charge_amount ELSE 0 END), 0) as today_revenue
            ")
            ->first();

        $stats = (array) $stats + (array) $todayStats;
        $stats['total_users'] = $user->hasRole(['admin', 'superadmin']) ? User::where('is_active', true)->count() : 0;
        $stats['active_rates'] = $user->hasRole(['admin', 'superadmin']) ? ExchangeRate::where('is_active', true)->count() : 0;

        $recentTransactions = Transaction::when($scope, fn($q) => $q->where('created_by', $scope))
            ->latest()
            ->take(10)
            ->get();

        $chartData = $this->buildChartData($scope);

        $usdToUgx = $this->usdToUgxRate();
        if ($usdToUgx) {
            $chartData['revenue'] = array_map(fn($r) => round($r * $usdToUgx), $chartData['revenue']);
        }

        return view('dashboard', compact('stats', 'recentTransactions', 'chartData', 'role', 'usdToUgx'));
    }

    private function usdToUgxRate(): ?float
    {
        return ExchangeRate::usdToUgx();
    }

    private function buildChartData(?int $scope): array
    {
        $startDate = now()->subDays(6)->startOfDay();

        $daily = DB::table('transactions')
            ->when($scope, fn($q) => $q->where('created_by', $scope))
            ->where('created_at', '>=', $startDate)
            ->selectRaw("DATE(created_at) as date, COUNT(*) as transactions, COALESCE(SUM(CASE WHEN status='completed' THEN charge_amount ELSE 0 END), 0) as revenue")
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get()
            ->keyBy('date');

        $labels = [];
        $transactions = [];
        $revenue = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $labels[] = now()->subDays($i)->format('M d');
            $transactions[] = (int) ($daily[$date]->transactions ?? 0);
            $revenue[] = (float) ($daily[$date]->revenue ?? 0);
        }

        return compact('labels', 'transactions', 'revenue');
    }
}

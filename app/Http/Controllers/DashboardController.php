<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $query = Transaction::query();

        if ($user->hasRole('agent')) {
            $query->where('created_by', $user->id);
        }

        $todayQuery = Transaction::whereDate('created_at', today());
        if ($user->hasRole('agent')) {
            $todayQuery->where('created_by', $user->id);
        }

        $stats = [
            'total_transactions' => (clone $query)->count(),
            'total_usd_sent' => (clone $query)->where('status', 'completed')->sum('total_usd_equivalent'),
            'total_revenue' => (clone $query)->where('status', 'completed')->sum('charge_amount'),
            'total_rmb_sent' => (clone $query)->where('status', 'completed')->sum('rmb_amount'),
            'pending_transactions' => (clone $query)->where('status', 'pending')->count(),
            'completed_transactions' => (clone $query)->where('status', 'completed')->count(),
            'cancelled_transactions' => (clone $query)->where('status', 'cancelled')->count(),
            'avg_transaction' => (clone $query)->where('status', 'completed')->avg('total_usd_equivalent') ?? 0,
            'total_users' => User::where('is_active', true)->count(),
            'active_rates' => \App\Models\ExchangeRate::where('is_active', true)->count(),
            'today_transactions' => (clone $todayQuery)->count(),
            'today_usd_sent' => (clone $todayQuery)->where('status', 'completed')->sum('total_usd_equivalent'),
            'today_revenue' => (clone $todayQuery)->where('status', 'completed')->sum('charge_amount'),
        ];

        $recentTransactions = (clone $query)
            ->with('creator')
            ->latest()
            ->take(10)
            ->get();

        return view('dashboard', compact('stats', 'recentTransactions'));
    }
}

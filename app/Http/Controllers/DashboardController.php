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

        $stats = [
            'total_transactions' => (clone $query)->count(),
            'total_revenue' => (clone $query)->where('status', 'completed')->sum('charge_amount'),
            'total_rmb_sent' => (clone $query)->where('status', 'completed')->sum('rmb_amount'),
            'pending_transactions' => (clone $query)->where('status', 'pending')->count(),
        ];

        $recentTransactions = (clone $query)
            ->with('creator')
            ->latest()
            ->take(10)
            ->get();

        return view('dashboard', compact('stats', 'recentTransactions'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentPerformanceController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->format('Y-m-d'));
        $dateTo = $request->input('date_to', now()->format('Y-m-d'));

        $agents = static::report($dateFrom, $dateTo);

        return view('agents.performance', compact('agents', 'dateFrom', 'dateTo'));
    }

    public static function report(string $dateFrom, string $dateTo)
    {
        $until = $dateTo . ' 23:59:59';

        return User::role('agent')
            ->withCount(['transactions as total_transactions' => function ($q) use ($dateFrom, $until) {
                $q->whereBetween('created_at', [$dateFrom, $until]);
            }])
            ->withCount(['transactions as completed_transactions' => function ($q) use ($dateFrom, $until) {
                $q->where('status', 'completed')
                  ->whereBetween('created_at', [$dateFrom, $until]);
            }])
            ->withCount(['transactions as pending_transactions' => function ($q) use ($dateFrom, $until) {
                $q->where('status', 'pending')
                  ->whereBetween('created_at', [$dateFrom, $until]);
            }])
            ->get()
            ->map(function ($agent) use ($dateFrom, $until) {
                $completed = Transaction::where('created_by', $agent->id)
                    ->where('status', 'completed')
                    ->whereBetween('created_at', [$dateFrom, $until]);

                $totalAmount = (clone $completed)->sum('total_usd_equivalent');
                $totalRevenue = (clone $completed)->sum('charge_amount');

                $successRate = $agent->total_transactions > 0
                    ? round(($agent->completed_transactions / $agent->total_transactions) * 100, 1)
                    : 0;

                return [
                    'agent' => $agent,
                    'total_transactions' => $agent->total_transactions,
                    'completed_transactions' => $agent->completed_transactions,
                    'pending_transactions' => $agent->pending_transactions,
                    'total_amount' => $totalAmount,
                    'total_revenue' => $totalRevenue,
                    'success_rate' => $successRate,
                ];
            })
            ->sortByDesc('total_revenue');
    }
}

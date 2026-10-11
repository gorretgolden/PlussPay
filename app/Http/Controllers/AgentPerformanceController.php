<?php

namespace App\Http\Controllers;

use App\Models\ExchangeRate;
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

        // Anyone who moved money in the window belongs on this page, whatever role
        // they hold. Filtering to the agent role alone hid work done by an admin and
        // left the page reading zero while Reports counted the same transfers.
        $creators = Transaction::whereBetween('created_at', [$dateFrom, $until])
            ->distinct()
            ->pluck('created_by');

        return User::where(fn ($q) => $q->role('agent')->orWhereIn('id', $creators))
            ->with('roles')
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
                // exchange_rate is the dollar to RMB rate. The UGX a dollar transfer was sold
                // at is rate_sell_ugx, and rows booked before that was recorded use today's rate.
                $todaySell = ExchangeRate::usdToUgx() ?? 0.0;
                $ugxPrice = fn ($t) => (float) ($t->rate_sell_ugx ?: $todaySell);

                $completed = Transaction::where('created_by', $agent->id)
                    ->where('status', 'completed')
                    ->whereBetween('created_at', [$dateFrom, $until])
                    ->get(['currency', 'amount', 'rate_sell_ugx', 'charge_amount', 'charge_ugx', 'total_usd_equivalent']);

                $successRate = $agent->total_transactions > 0
                    ? round(($agent->completed_transactions / $agent->total_transactions) * 100, 1)
                    : 0;

                return [
                    'agent' => $agent,
                    'role' => $agent->roles->pluck('name')->implode(', ') ?: 'No role',
                    'total_transactions' => $agent->total_transactions,
                    'completed_transactions' => $agent->completed_transactions,
                    'pending_transactions' => $agent->pending_transactions,
                    'total_amount' => $completed->sum('total_usd_equivalent'),
                    'total_revenue' => $completed->sum('charge_amount'),
                    // UGX comes from what each transfer was actually priced at, the same
                    // way Reports and the dashboard work, so the pages cannot drift apart.
                    'sent_ugx' => $completed->sum(fn ($t) => $t->currency === 'UGX'
                        ? (float) $t->amount
                        : (float) $t->total_usd_equivalent * $ugxPrice($t)),
                    'revenue_ugx' => $completed->sum(fn ($t) => (float) $t->charge_ugx > 0
                        ? (float) $t->charge_ugx
                        : (float) $t->charge_amount * $ugxPrice($t)),
                    'success_rate' => $successRate,
                ];
            })
            ->sortByDesc('revenue_ugx');
    }
}

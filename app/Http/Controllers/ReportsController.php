<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());

        $query = Transaction::whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);

        if (auth()->user()->hasRole('agent')) {
            $query->where('created_by', auth()->id());
        }

        $transactions = (clone $query)->latest()->get();

        $stats = [
            'total_transactions' => $transactions->count(),
            'completed' => $transactions->where('status', 'completed')->count(),
            'pending' => $transactions->where('status', 'pending')->count(),
            'cancelled' => $transactions->where('status', 'cancelled')->count(),
            'total_usd_sent' => $transactions->where('status', 'completed')->sum('total_usd_equivalent'),
            'total_rmb_sent' => $transactions->where('status', 'completed')->sum('rmb_amount'),
            'total_revenue' => $transactions->where('status', 'completed')->sum('charge_amount'),
            'avg_transaction' => $transactions->where('status', 'completed')->count()
                ? $transactions->where('status', 'completed')->avg('total_usd_equivalent')
                : 0,
        ];

        $byCurrency = $transactions->where('status', 'completed')
            ->groupBy('currency')
            ->map(fn ($items) => [
                'count' => $items->count(),
                'total' => $items->sum('amount'),
                'usd_equivalent' => $items->sum('total_usd_equivalent'),
            ]);

        $byAgent = Transaction::with('creator')
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->where('status', 'completed')
            ->select('created_by', DB::raw('count(*) as count'), DB::raw('sum(total_usd_equivalent) as total_usd'), DB::raw('sum(charge_amount) as total_revenue'))
            ->groupBy('created_by')
            ->get();

        $dailySummary = Transaction::whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->where('status', 'completed')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'), DB::raw('sum(total_usd_equivalent) as total_usd'), DB::raw('sum(charge_amount) as revenue'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        return view('reports.index', compact('stats', 'byCurrency', 'byAgent', 'dailySummary', 'dateFrom', 'dateTo', 'transactions'));
    }

    public function exportCsv(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());

        $query = Transaction::whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);

        if (auth()->user()->hasRole('agent')) {
            $query->where('created_by', auth()->id());
        }

        $transactions = $query->with('creator')->latest()->get();

        $filename = 'pluspay_report_' . $dateFrom . '_to_' . $dateTo . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($transactions) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Receipt #', 'Date', 'Sender Name', 'Sender Contact', 'Receiver Name', 'Receiver Contact', 'Currency', 'Amount', 'USD Equivalent', 'Exchange Rate', 'RMB Amount', 'Charge Rate %', 'Charge Amount', 'Status', 'Agent']);

            foreach ($transactions as $t) {
                fputcsv($file, [
                    $t->receipt_number,
                    $t->created_at->format('Y-m-d H:i'),
                    $t->sender_name,
                    $t->sender_contact,
                    $t->receiver_name,
                    $t->receiver_contact,
                    $t->currency,
                    $t->amount,
                    $t->total_usd_equivalent,
                    $t->exchange_rate,
                    $t->rmb_amount,
                    $t->charge_rate,
                    $t->charge_amount,
                    $t->status,
                    $t->creator?->name ?? '-',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}

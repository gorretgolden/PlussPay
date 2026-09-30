<?php

namespace App\Http\Controllers;

use App\Models\ExchangeRate;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = Transaction::with('creator');

        if ($user->hasRole('agent')) {
            $query->where('created_by', $user->id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                  ->orWhere('sender_name', 'like', "%{$search}%")
                  ->orWhere('receiver_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $transactions = $query->latest()->paginate(20);

        return view('transactions.index', compact('transactions'));
    }

    public function create()
    {
        $usdToRmb = ExchangeRate::getActiveRate('USD', 'RMB');
        $ugxToUsd = ExchangeRate::getActiveRate('UGX', 'USD');

        return view('transactions.create', compact('usdToRmb', 'ugxToUsd'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sender_name' => 'required|string|max:255',
            'sender_contact' => 'required|string|max:255',
            'receiver_name' => 'required|string|max:255',
            'receiver_contact' => 'required|string|max:255',
            'currency' => 'required|in:UGX,USD',
            'amount' => 'required|numeric|min:0.01',
            'charge_rate' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $usdToRmbRate = ExchangeRate::getActiveRate('USD', 'RMB');
        if (!$usdToRmbRate) {
            return back()->withErrors(['exchange_rate' => 'USD to RMB rate not set. Please contact admin.']);
        }

        $amount = (float) $validated['amount'];
        $currency = $validated['currency'];

        if ($currency === 'UGX') {
            $ugxToUsdRate = ExchangeRate::getActiveRate('UGX', 'USD');
            if (!$ugxToUsdRate) {
                return back()->withErrors(['exchange_rate' => 'UGX to USD rate not set. Please contact admin.']);
            }
            $usdEquivalent = bcmul((string) $amount, (string) $ugxToUsdRate->rate, 2);
        } else {
            $usdEquivalent = (string) $amount;
        }

        $rmbAmount = bcmul($usdEquivalent, (string) $usdToRmbRate->rate, 2);
        $chargeRate = (float) $validated['charge_rate'];
        $chargeAmount = bcmul($usdEquivalent, (string) ($chargeRate / 100), 2);

        $transaction = Transaction::create([
            'receipt_number' => Transaction::generateReceiptNumber(),
            'sender_name' => $validated['sender_name'],
            'sender_contact' => $validated['sender_contact'],
            'receiver_name' => $validated['receiver_name'],
            'receiver_contact' => $validated['receiver_contact'],
            'currency' => $currency,
            'amount' => $amount,
            'exchange_rate' => $usdToRmbRate->rate,
            'rmb_amount' => $rmbAmount,
            'charge_rate' => $chargeRate,
            'charge_amount' => $chargeAmount,
            'total_usd_equivalent' => $usdEquivalent,
            'status' => 'completed',
            'created_by' => auth()->id(),
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()->route('receipts.show', $transaction->receipt_number);
    }

    public function show(Transaction $transaction)
    {
        return view('transactions.show', compact('transaction'));
    }

    public function updateStatus(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,completed,cancelled',
        ]);

        $transaction->update(['status' => $validated['status']]);

        return back()->with('success', 'Transaction status updated.');
    }
}

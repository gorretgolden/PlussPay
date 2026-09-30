<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
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
            'sender_name' => 'required|string|max:100|min:2',
            'sender_contact' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'receiver_name' => 'required|string|max:100|min:2',
            'receiver_contact' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'currency' => 'required|in:UGX,USD',
            'amount' => 'required|numeric|min:100|max:100000000',
            'charge_rate' => 'required|numeric|min:0|max:100',
            'notes' => 'nullable|string|max:500',
        ], [
            'sender_name.required' => 'Sender name is required.',
            'sender_name.min' => 'Sender name must be at least 2 characters.',
            'sender_contact.required' => 'Sender contact is required.',
            'sender_contact.regex' => 'Enter a valid phone number (e.g. +256 700 000 000).',
            'receiver_name.required' => 'Receiver name is required.',
            'receiver_name.min' => 'Receiver name must be at least 2 characters.',
            'receiver_contact.required' => 'Receiver contact is required.',
            'receiver_contact.regex' => 'Enter a valid phone number (e.g. +86 138 0000 0000).',
            'amount.required' => 'Amount is required.',
            'amount.min' => 'Minimum amount is 100.',
            'amount.max' => 'Maximum amount is 100,000,000.',
            'charge_rate.required' => 'Charge rate is required.',
            'charge_rate.max' => 'Charge rate cannot exceed 100%.',
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
            'sender_name' => trim($validated['sender_name']),
            'sender_contact' => trim($validated['sender_contact']),
            'receiver_name' => trim($validated['receiver_name']),
            'receiver_contact' => trim($validated['receiver_contact']),
            'currency' => $currency,
            'amount' => $amount,
            'exchange_rate' => $usdToRmbRate->rate,
            'rmb_amount' => $rmbAmount,
            'charge_rate' => $chargeRate,
            'charge_amount' => $chargeAmount,
            'total_usd_equivalent' => $usdEquivalent,
            'status' => auth()->user()->hasRole('admin') ? 'completed' : 'pending',
            'created_by' => auth()->id(),
            'notes' => !empty($validated['notes']) ? trim($validated['notes']) : null,
        ]);

        ActivityLog::create([
            'transaction_id' => $transaction->id,
            'user_id' => auth()->id(),
            'action' => 'created',
            'description' => 'Transaction created',
            'new_value' => $transaction->status,
        ]);

        return redirect()->route('receipts.show', $transaction->receipt_number);
    }

    public function show(Transaction $transaction)
    {
        $transaction->load('activityLogs.user');

        return view('transactions.show', compact('transaction'));
    }

    public function updateStatus(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,completed,cancelled',
        ]);

        $oldStatus = $transaction->status;
        $transaction->update(['status' => $validated['status']]);

        $action = match ($validated['status']) {
            'completed' => 'approved',
            'cancelled' => 'rejected',
            default => 'status_changed',
        };

        ActivityLog::create([
            'transaction_id' => $transaction->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => "Status changed from {$oldStatus} to {$validated['status']}",
            'old_value' => $oldStatus,
            'new_value' => $validated['status'],
        ]);

        return back()->with('success', 'Transaction status updated to ' . ucfirst($validated['status']) . '.');
    }
}

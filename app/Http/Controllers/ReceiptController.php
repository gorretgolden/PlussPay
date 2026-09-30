<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = Transaction::with('creator')->where('status', 'completed');

        if ($user->hasRole('agent')) {
            $query->where('created_by', $user->id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('receipt_number', 'like', "%{$search}%");
        }

        $receipts = $query->latest()->paginate(20);

        return view('receipts.index', compact('receipts'));
    }

    public function show(string $receiptNumber)
    {
        $transaction = Transaction::where('receipt_number', $receiptNumber)->firstOrFail();

        return view('receipts.show', compact('transaction'));
    }

    public function print(string $receiptNumber)
    {
        $transaction = Transaction::where('receipt_number', $receiptNumber)->firstOrFail();

        return view('receipts.print', compact('transaction'));
    }
}

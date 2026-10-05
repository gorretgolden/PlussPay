<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    public function index(Request $request)
    {
        $receipts = Transaction::with('creator')
            ->where('status', 'completed')
            ->filtered($request)
            ->when($request->user()->hasRole('agent'), fn($q) => $q->where('created_by', $request->user()->id))
            ->latest()
            ->paginate(20)
            ->withQueryString();

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

<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Transaction;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    public function index(Request $request)
    {
        $receipts = Transaction::with('creator')
            ->where('status', 'completed')
            ->visibleTo($request->user())
            ->filtered($request)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('receipts.index', compact('receipts'));
    }

    public function show(Request $request, string $receiptNumber)
    {
        $transaction = $this->visibleReceipt($request, $receiptNumber);

        return view('receipts.show', compact('transaction'));
    }

    public function print(Request $request, string $receiptNumber)
    {
        $transaction = $this->visibleReceipt($request, $receiptNumber);

        ActivityLog::create([
            'transaction_id' => $transaction->id,
            'user_id' => $request->user()->id,
            'action' => 'receipt_printed',
            'description' => "Receipt {$transaction->receipt_number} printed",
        ]);

        return view('receipts.print', compact('transaction'));
    }

    private function visibleReceipt(Request $request, string $receiptNumber): Transaction
    {
        $transaction = Transaction::visibleTo($request->user())
            ->where('receipt_number', $receiptNumber)
            ->firstOrFail();

        return $transaction;
    }
}

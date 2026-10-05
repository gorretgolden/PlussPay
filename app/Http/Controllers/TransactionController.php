<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $transactions = Transaction::with('creator')
            ->filtered($request)
            ->when($request->user()->hasRole('agent'), fn($q) => $q->where('created_by', $request->user()->id))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $creators = User::whereHas('transactions')->orderBy('name')->get();

        return view('transactions.index', compact('transactions', 'creators'));
    }

    public function create()
    {
        $usdToRmb = ExchangeRate::getActiveRate('USD', 'RMB');
        $usdToUgx = ExchangeRate::usdToUgx();
        $ugxToUsdRate = $usdToUgx ? 1 / $usdToUgx : null;
        $defaultChargeRate = \App\Models\Setting::get('default_charge_rate', 5);

        return view('transactions.create', compact('usdToRmb', 'ugxToUsdRate', 'defaultChargeRate'));
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
            'purpose' => 'nullable|string|max:100',
            'purpose_other' => 'required_if:purpose,Other|nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ], [
            'purpose_other.required_if' => 'Please say what the other reason is.',
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
            $usdToUgx = ExchangeRate::usdToUgx();
            if (!$usdToUgx) {
                return back()->withErrors(['exchange_rate' => 'UGX to USD rate not set. Please contact admin.']);
            }
            $usdEquivalent = bcdiv((string) $amount, (string) $usdToUgx, 2);
        } else {
            $usdEquivalent = (string) $amount;
        }

        $rmbAmount = bcmul($usdEquivalent, (string) $usdToRmbRate->rate, 2);
        $chargeRate = (float) \App\Models\Setting::get('default_charge_rate', 5);
        $chargeAmount = bcmul($usdEquivalent, (string) ($chargeRate / 100), 2);

        $purpose = $validated['purpose'] ?? null;
        if ($purpose === 'Other') {
            $purpose = trim($validated['purpose_other'] ?? '') ?: 'Other';
        }

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
            'purpose' => $purpose ? trim($purpose) : null,
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

    public function showBulkImport()
    {
        return view('transactions.bulk-import');
    }

    public function bulkImport(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls|max:10240',
        ]);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();

        try {
            if ($extension === 'csv') {
                $rows = $this->parseCsv($file);
            } else {
                $rows = $this->parseExcel($file);
            }

            if (empty($rows)) {
                return back()->withErrors(['file' => 'The file is empty or could not be parsed.']);
            }

            $usdToRmbRate = ExchangeRate::getActiveRate('USD', 'RMB');
            if (!$usdToRmbRate) {
                return back()->withErrors(['exchange_rate' => 'USD to RMB rate not set. Please configure exchange rates first.']);
            }

            $defaultChargeRate = \App\Models\Setting::get('default_charge_rate', 5);
            $imported = 0;
            $errors = [];

            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;

                if (empty($row['sender_name']) || empty($row['receiver_name']) || empty($row['amount'])) {
                    $errors[] = "Row {$rowNumber}: Missing required fields (sender_name, receiver_name, or amount)";
                    continue;
                }

                $currency = strtoupper(trim($row['currency'] ?? 'USD'));
                if (!in_array($currency, ['UGX', 'USD'])) {
                    $errors[] = "Row {$rowNumber}: Invalid currency '{$currency}'. Must be UGX or USD.";
                    continue;
                }

                $amount = (float) ($row['amount'] ?? 0);
                if ($amount < 100) {
                    $errors[] = "Row {$rowNumber}: Amount must be at least 100 {$currency}";
                    continue;
                }

                if ($currency === 'UGX') {
                    $usdToUgx = ExchangeRate::usdToUgx();
                    if (!$usdToUgx) {
                        $errors[] = "Row {$rowNumber}: UGX to USD rate not set";
                        continue;
                    }
                    $usdEquivalent = bcdiv((string) $amount, (string) $usdToUgx, 2);
                } else {
                    $usdEquivalent = (string) $amount;
                }

                $rmbAmount = bcmul($usdEquivalent, (string) $usdToRmbRate->rate, 2);
                $chargeRate = (float) $defaultChargeRate;
                $chargeAmount = bcmul($usdEquivalent, (string) ($chargeRate / 100), 2);

                try {
                    $transaction = Transaction::create([
                        'receipt_number' => Transaction::generateReceiptNumber(),
                        'sender_name' => trim($row['sender_name']),
                        'sender_contact' => trim($row['sender_contact'] ?? ''),
                        'receiver_name' => trim($row['receiver_name']),
                        'receiver_contact' => trim($row['receiver_contact'] ?? ''),
                        'currency' => $currency,
                        'amount' => $amount,
                        'exchange_rate' => $usdToRmbRate->rate,
                        'rmb_amount' => $rmbAmount,
                        'charge_rate' => $chargeRate,
                        'charge_amount' => $chargeAmount,
                        'total_usd_equivalent' => $usdEquivalent,
                        'status' => 'completed',
                        'created_by' => auth()->id(),
                        'purpose' => !empty($row['purpose']) ? trim($row['purpose']) : null,
                        'notes' => !empty($row['notes']) ? trim($row['notes']) : null,
                    ]);

                    ActivityLog::create([
                        'transaction_id' => $transaction->id,
                        'user_id' => auth()->id(),
                        'action' => 'bulk_imported',
                        'description' => 'Transaction imported via bulk upload',
                        'new_value' => 'completed',
                    ]);

                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = "Row {$rowNumber}: " . $e->getMessage();
                }
            }

            $message = "Successfully imported {$imported} transaction(s).";
            if (!empty($errors)) {
                $message .= ' ' . count($errors) . ' row(s) had errors.';
            }

            return back()->with('success', $message)->with('import_errors', $errors);
        } catch (\Exception $e) {
            return back()->withErrors(['file' => 'Error processing file: ' . $e->getMessage()]);
        }
    }

    private function parseCsv($file): array
    {
        $rows = [];
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            return [];
        }

        $headers = array_map(fn($h) => strtolower(trim($h)), $headers);

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) === 1 && empty($data[0])) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = $data[$index] ?? '';
            }
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }

    private function parseExcel($file): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        $headers = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $value = $sheet->getCell([$col, 1])->getValue();
            $headers[$col] = strtolower(trim($value));
        }

        $rows = [];
        for ($row = 2; $row <= $highestRow; $row++) {
            $rowData = [];
            $isEmpty = true;
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $value = $sheet->getCell([$col, $row])->getValue();
                $rowData[$headers[$col]] = $value ?? '';
                if (!empty($value)) {
                    $isEmpty = false;
                }
            }
            if (!$isEmpty) {
                $rows[] = $rowData;
            }
        }

        return $rows;
    }
}

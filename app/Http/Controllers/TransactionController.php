<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    private const ALLOWED_STATUS_TRANSITIONS = [
        'pending' => ['completed', 'cancelled'],
        'completed' => ['cancelled'],
        'cancelled' => [],
    ];

    public function index(Request $request)
    {
        $transactions = Transaction::with('creator')
            ->visibleTo($request->user())
            ->filtered($request)
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
        $serviceFeeUgx = (float) \App\Models\Setting::get('service_fee_ugx', 2000);
        $serviceFeeUsd = (float) \App\Models\Setting::get('service_fee_usd', 100);

        // One-time token so a replayed or double-posted submit cannot mint a second receipt.
        $formId = (string) Str::uuid();
        session(['transaction_form_id' => $formId]);

        return view('transactions.create', compact('usdToRmb', 'ugxToUsdRate', 'serviceFeeUgx', 'serviceFeeUsd', 'formId'));
    }

    /**
     * The fee is quoted as a UGX amount for each block of USD sent, so it scales
     * with the amount. Returns the UGX charge, its USD equivalent for accounting,
     * and the equivalent flat percentage. Null when it cannot be calculated.
     */
    private function serviceFee(string $usdEquivalent): ?array
    {
        $feeUgx = (float) \App\Models\Setting::get('service_fee_ugx', 2000);
        $feeBlockUsd = (float) \App\Models\Setting::get('service_fee_usd', 100);
        $usdToUgx = (float) (ExchangeRate::usdToUgx() ?? 0);

        if ($usdToUgx <= 0 || $feeBlockUsd <= 0) {
            return null;
        }

        $ugxPerUsd = $feeUgx / $feeBlockUsd;
        $chargeUgx = bcmul($usdEquivalent, (string) $ugxPerUsd, 2);

        return [
            'ugx' => $chargeUgx,
            'usd' => bcdiv($chargeUgx, (string) $usdToUgx, 2),
            'rate' => $ugxPerUsd * 100 / $usdToUgx,
        ];
    }

    public function store(Request $request)
    {
        $minAmount = (float) \App\Models\Setting::get('min_transaction_amount', 100);
        $maxAmount = (float) \App\Models\Setting::get('max_transaction_amount', 100000000);

        $validated = $request->validate([
            'form_id' => 'required|string',
            'sender_name' => 'required|string|max:100|min:2',
            'sender_contact' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'receiver_name' => 'required|string|max:100|min:2',
            'receiver_contact' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'currency' => 'required|in:UGX,USD',
            'amount' => "required|numeric|min:{$minAmount}|max:{$maxAmount}|decimal:0,2",
            'purpose' => 'nullable|string|max:100',
            'purpose_other' => 'required_if:purpose,Other|nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ], [
            'form_id.required' => 'This form has expired. Please reload the page and try again.',
            'amount.min' => "Minimum amount is {$minAmount}.",
            'amount.max' => "Maximum amount is ".number_format($maxAmount).".",
            'amount.decimal' => 'Amount cannot have more than 2 decimal places.',
            'purpose_other.required_if' => 'Please say what the other reason is.',
            'sender_name.required' => 'Sender name is required.',
            'sender_name.min' => 'Sender name must be at least 2 characters.',
            'sender_contact.regex' => 'Enter a valid phone number (e.g. +256 700 000 000).',
            'receiver_name.required' => 'Receiver name is required.',
            'receiver_name.min' => 'Receiver name must be at least 2 characters.',
            'receiver_contact.regex' => 'Enter a valid phone number (e.g. +86 138 0000 0000).',
            'amount.required' => 'Amount is required.',
        ]);

        if (! hash_equals((string) session('transaction_form_id', ''), $validated['form_id'])) {
            return back()->withErrors(['form_id' => 'This transaction was already submitted. Start a new form to send another.']);
        }

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
        $fee = $this->serviceFee($usdEquivalent);
        if ($fee === null) {
            return back()->withErrors(['exchange_rate' => 'UGX rate not set. The service fee cannot be calculated.'])->withInput();
        }

        $purpose = $validated['purpose'] ?? null;
        if ($purpose === 'Other') {
            $purpose = trim($validated['purpose_other'] ?? '') ?: 'Other';
        }

        $transaction = Transaction::createWithReceiptNumber([
            'sender_name' => trim($validated['sender_name']),
            'sender_contact' => trim($validated['sender_contact'] ?? ''),
            'receiver_name' => trim($validated['receiver_name']),
            'receiver_contact' => trim($validated['receiver_contact'] ?? ''),
            'currency' => $currency,
            'amount' => $amount,
            'exchange_rate' => $usdToRmbRate->rate,
            'rmb_amount' => $rmbAmount,
            'charge_rate' => $fee['rate'],
            'charge_amount' => $fee['usd'],
            'charge_ugx' => $fee['ugx'],
            'total_usd_equivalent' => $usdEquivalent,
            'status' => auth()->user()->hasRole(['admin', 'superadmin']) ? 'completed' : 'pending',
            'created_by' => auth()->id(),
            'purpose' => $purpose ? trim($purpose) : null,
            'notes' => !empty($validated['notes']) ? trim($validated['notes']) : null,
        ]);

        session(['transaction_form_id' => (string) Str::uuid()]);

        ActivityLog::create([
            'transaction_id' => $transaction->id,
            'user_id' => auth()->id(),
            'action' => 'created',
            'description' => 'Transaction created',
            'new_value' => $transaction->status,
        ]);

        return redirect()->route('receipts.show', $transaction->receipt_number);
    }

    public function show(Request $request, Transaction $transaction)
    {
        abort_unless($this->canView($request, $transaction), 403, 'You can only open transactions you created.');

        $transaction->load('activityLogs.user');

        return view('transactions.show', compact('transaction'));
    }

    public function updateStatus(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,completed,cancelled',
        ]);

        $oldStatus = $transaction->status;
        $newStatus = $validated['status'];

        if ($oldStatus === $newStatus) {
            return back()->with('warning', 'That transaction is already ' . $newStatus . '.');
        }

        if (!in_array($newStatus, self::ALLOWED_STATUS_TRANSITIONS[$oldStatus] ?? [], true)) {
            return back()->withErrors([
                'status' => "Cannot move a {$oldStatus} transaction to {$newStatus}.",
            ]);
        }

        $transaction->update(['status' => $newStatus]);

        $action = match ($newStatus) {
            'completed' => 'approved',
            'cancelled' => 'rejected',
            default => 'status_changed',
        };

        ActivityLog::create([
            'transaction_id' => $transaction->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => "Status changed from {$oldStatus} to {$newStatus}",
            'old_value' => $oldStatus,
            'new_value' => $newStatus,
        ]);

        return back()->with('success', 'Transaction status updated to ' . ucfirst($newStatus) . '.');
    }

    private function canView(Request $request, Transaction $transaction): bool
    {
        return $transaction->created_by === $request->user()->id
            || $request->user()->hasRole(['superadmin', 'admin']);
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

            if (count($rows) > 1000) {
                return back()->withErrors(['file' => 'A file can hold at most 1,000 rows. Split it into smaller files.']);
            }

            $minAmount = (float) \App\Models\Setting::get('min_transaction_amount', 100);
            $maxAmount = (float) \App\Models\Setting::get('max_transaction_amount', 100000000);
            if (! ExchangeRate::usdToUgx()) {
                return back()->withErrors(['file' => 'UGX rate not set. The service fee cannot be calculated.']);
            }
            $imported = 0;
            $errors = [];

            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;

                $checked = Validator::make($row, [
                    'sender_name' => 'required|string|max:100|min:2',
                    'sender_contact' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
                    'receiver_name' => 'required|string|max:100|min:2',
                    'receiver_contact' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
                    'currency' => 'required|in:UGX,USD',
                    'amount' => "required|numeric|min:{$minAmount}|max:{$maxAmount}",
                    'purpose' => 'nullable|string|max:100',
                    'notes' => 'nullable|string|max:500',
                ]);

                if ($checked->fails()) {
                    $errors[] = "Row {$rowNumber}: " . implode(' ', $checked->errors()->all());
                    continue;
                }

                $data = $checked->validated();
                $currency = strtoupper(trim($data['currency']));
                $amount = (float) $data['amount'];

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
                $fee = $this->serviceFee($usdEquivalent);
                if ($fee === null) {
                    $errors[] = "Row {$rowNumber}: UGX to USD rate not set";
                    continue;
                }

                try {
                    $transaction = Transaction::createWithReceiptNumber([
                        'sender_name' => trim($data['sender_name']),
                        'sender_contact' => trim($data['sender_contact'] ?? ''),
                        'receiver_name' => trim($data['receiver_name']),
                        'receiver_contact' => trim($data['receiver_contact'] ?? ''),
                        'currency' => $currency,
                        'amount' => $amount,
                        'exchange_rate' => $usdToRmbRate->rate,
                        'rmb_amount' => $rmbAmount,
                        'charge_rate' => $fee['rate'],
                        'charge_amount' => $fee['usd'],
                        'charge_ugx' => $fee['ugx'],
                        'total_usd_equivalent' => $usdEquivalent,
                        'status' => 'completed',
                        'created_by' => auth()->id(),
                        'purpose' => !empty($data['purpose']) ? trim($data['purpose']) : null,
                        'notes' => !empty($data['notes']) ? trim($data['notes']) : null,
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
                    report($e);
                    $errors[] = "Row {$rowNumber}: Could not be saved. Check the sender, receiver and amount values.";
                }
            }

            if ($imported > 0) {
                ActivityLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'bulk_import',
                    'description' => "Bulk imported {$imported} transaction(s), " . count($errors) . ' row(s) rejected',
                    'new_value' => (string) $imported,
                ]);
            }

            $message = "Successfully imported {$imported} transaction(s).";
            if (!empty($errors)) {
                $message .= ' ' . count($errors) . ' row(s) had errors.';
            }

            return back()->with('success', $message)->with('import_errors', $errors);
        } catch (\Exception $e) {
            report($e);
            return back()->withErrors(['file' => 'That file could not be read. Check that it is a valid CSV or Excel export.']);
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

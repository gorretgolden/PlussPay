<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * Margin made on the currency itself: these dollars are priced to the customer at the
     * selling rate and cost the buying rate to obtain, so the gap times the dollars is what
     * one transfer earns before any service fee.
     *
     * @return array{buy: float|null, sell: float|null, ugx: string, usd: string}
     */
    private function fxProfit(string $usdEquivalent): array
    {
        $pair = ExchangeRate::usdUgxPair();
        if (! $pair || $pair['sell'] <= 0) {
            return ['buy' => null, 'sell' => null, 'ugx' => '0.00', 'usd' => '0.00'];
        }

        $ugx = bcmul($usdEquivalent, bcsub((string) $pair['sell'], (string) $pair['buy'], 6), 2);

        return [
            'buy' => $pair['buy'],
            'sell' => $pair['sell'],
            'ugx' => $ugx,
            'usd' => bcdiv($ugx, (string) $pair['sell'], 2),
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
        $fx = $this->fxProfit($usdEquivalent);

        $purpose = $validated['purpose'] ?? null;
        if ($purpose === 'Other') {
            $purpose = trim($validated['purpose_other'] ?? '') ?: 'Other';
        }

        // The booking and the ledger entries it posts save together, so a failure part way
        // through cannot leave a transfer in the books with no money moved for it.
        $transaction = DB::transaction(fn () => Transaction::createWithReceiptNumber([
            'sender_name' => trim($validated['sender_name']),
            'sender_contact' => trim($validated['sender_contact'] ?? ''),
            'receiver_name' => trim($validated['receiver_name']),
            'receiver_contact' => trim($validated['receiver_contact'] ?? ''),
            'currency' => $currency,
            'amount' => $amount,
            'exchange_rate' => $usdToRmbRate->rate,
            'rate_buy_ugx' => $fx['buy'],
            'rate_sell_ugx' => $fx['sell'],
            'rmb_amount' => $rmbAmount,
            'charge_rate' => $fee['rate'],
            'charge_amount' => $fee['usd'],
            'charge_ugx' => $fee['ugx'],
            'fx_profit_ugx' => $fx['ugx'],
            'fx_profit_usd' => $fx['usd'],
            'total_usd_equivalent' => $usdEquivalent,
            // Nothing is booked as done until an approval signs it off, whoever typed it in.
            'status' => 'pending',
            'created_by' => auth()->id(),
            'purpose' => $purpose ? trim($purpose) : null,
            'notes' => !empty($validated['notes']) ? trim($validated['notes']) : null,
        ]));

        session(['transaction_form_id' => (string) Str::uuid()]);

        ActivityLog::create([
            'transaction_id' => $transaction->id,
            'user_id' => auth()->id(),
            'action' => 'created',
            'description' => 'Transaction created, waiting for approval',
            'new_value' => $transaction->status,
        ]);

        return redirect()->route('receipts.show', $transaction->receipt_number)
            ->with('success', "{$transaction->receipt_number} booked. It shows as Pending until an admin approves it.");
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
            'reason' => 'nullable|string|max:300',
        ]);

        $oldStatus = $transaction->status;
        $newStatus = $validated['status'];
        $reason = trim($validated['reason'] ?? '');

        if ($oldStatus === $newStatus) {
            return back()->with('warning', 'That transaction is already ' . $newStatus . '.');
        }

        if (!in_array($newStatus, self::ALLOWED_STATUS_TRANSITIONS[$oldStatus] ?? [], true)) {
            return back()->withErrors([
                'status' => "Cannot move a {$oldStatus} transaction to {$newStatus}.",
            ]);
        }

        // A cancellation takes money back out of the till, so it is the one status change
        // that has to say why. The reason goes into the trail for whoever checks the books later.
        if ($newStatus === 'cancelled' && $reason === '') {
            return back()->withErrors([
                'reason' => 'Say why this transfer is being cancelled.',
            ]);
        }

        $transaction->update(['status' => $newStatus]);

        $action = match ($newStatus) {
            'completed' => 'approved',
            'cancelled' => 'cancelled',
            default => 'status_changed',
        };

        $description = match ($newStatus) {
            'cancelled' => "Cancelled a {$oldStatus} transfer: {$reason}",
            'completed' => "Approved from {$oldStatus}",
            default => "Status changed from {$oldStatus} to {$newStatus}",
        };

        ActivityLog::create([
            'transaction_id' => $transaction->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => $description,
            'old_value' => $oldStatus,
            'new_value' => $newStatus,
        ]);

        return back()->with('success', $this->statusFlash($newStatus, $transaction));
    }

    /**
     * Approved and cancelled transfers both move cash in the books, so the message says
     * what happened to the till instead of only naming the new status.
     */
    private function statusFlash(string $status, Transaction $transaction): string
    {
        $collected = $transaction->collectedAmount();

        return match ($status) {
            'cancelled' => "{$transaction->receipt_number} cancelled. {$collected} taken back out of the ledger.",
            'completed' => "{$transaction->receipt_number} approved. {$collected} posted to the ledger.",
            default => "{$transaction->receipt_number} is now " . ucfirst($status) . '.',
        };
    }

    /**
     * The approvals desk: everything booked and not yet approved, oldest first, with the
     * person and the time beside each so a batch can be signed off without opening them one by one.
     */
    public function approvals(Request $request)
    {
        $pending = Transaction::with('creator')
            ->visibleTo($request->user())
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('transactions.approvals', [
            'pending' => $pending,
            'totalPending' => Transaction::visibleTo($request->user())->where('status', 'pending')->count(),
        ]);
    }

    /**
     * Approving posts the till deposit and the agent commission, so each transfer is saved
     * through the model rather than a bulk query update, which would skip the ledger.
     */
    public function bulkApprove(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer',
        ]);

        $approved = DB::transaction(function () use ($validated) {
            $count = 0;

            $transactions = Transaction::whereIn('id', $validated['ids'])
                ->where('status', 'pending')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            foreach ($transactions as $transaction) {
                $transaction->update(['status' => 'completed']);

                ActivityLog::create([
                    'transaction_id' => $transaction->id,
                    'user_id' => auth()->id(),
                    'action' => 'approved',
                    'description' => 'Approved from pending',
                    'old_value' => 'pending',
                    'new_value' => 'completed',
                ]);

                $count++;
            }

            return $count;
        });

        if ($approved === 0) {
            return back()->with('warning', 'Those transfers are no longer waiting for approval.');
        }

        $skipped = count($validated['ids']) - $approved;

        return back()->with('success', $approved === 1
            ? 'One transfer approved and posted to the ledger.'
            : "{$approved} transfers approved and posted to the ledger." . ($skipped > 0 ? " {$skipped} had already been actioned." : ''));
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

            // Uploading the same file twice used to book every transfer a second time, so any
            // row that repeats the file or the last week of bookings is rejected.
            $seenInFile = [];
            $bookedRecently = Transaction::where('created_at', '>=', now()->subDays(7))
                ->get(['receipt_number', 'sender_contact', 'receiver_contact', 'currency', 'amount', 'created_at'])
                ->keyBy(fn ($t) => $this->importMatchKey($t->sender_contact, $t->receiver_contact, $t->currency, $t->amount));

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

                $sender = trim($data['sender_contact'] ?? '');
                $receiver = trim($data['receiver_contact'] ?? '');
                $matchKey = $this->importMatchKey($sender, $receiver, $currency, $amount);

                if (isset($seenInFile[$matchKey])) {
                    $errors[] = "Row {$rowNumber}: repeats row {$seenInFile[$matchKey]} in this file. If both are real transfers, book the second one on its own form.";
                    continue;
                }
                $seenInFile[$matchKey] = $rowNumber;

                $already = $bookedRecently->get($matchKey);
                if ($already) {
                    $errors[] = "Row {$rowNumber}: matches {$already->receipt_number}, already booked on " . $already->created_at->format('M d, Y') . '. Delete that transfer first if it was entered by mistake.';
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
                $fee = $this->serviceFee($usdEquivalent);
                if ($fee === null) {
                    $errors[] = "Row {$rowNumber}: UGX to USD rate not set";
                    continue;
                }
                $fx = $this->fxProfit($usdEquivalent);

                try {
                    // One transaction per row: the booking and the ledger entries it posts are
                    // either all saved or none of them are, so a bad row cannot leave half a
                    // transfer in the books.
                    DB::transaction(function () use ($data, $sender, $receiver, $currency, $amount, $usdToRmbRate, $rmbAmount, $fee, $fx, $usdEquivalent) {
                        $transaction = Transaction::createWithReceiptNumber([
                            'sender_name' => trim($data['sender_name']),
                            'sender_contact' => $sender,
                            'receiver_name' => trim($data['receiver_name']),
                            'receiver_contact' => $receiver,
                            'currency' => $currency,
                            'amount' => $amount,
                            'exchange_rate' => $usdToRmbRate->rate,
                            'rate_buy_ugx' => $fx['buy'],
                            'rate_sell_ugx' => $fx['sell'],
                            'rmb_amount' => $rmbAmount,
                            'charge_rate' => $fee['rate'],
                            'charge_amount' => $fee['usd'],
                            'charge_ugx' => $fee['ugx'],
                            'fx_profit_ugx' => $fx['ugx'],
                            'fx_profit_usd' => $fx['usd'],
                            'total_usd_equivalent' => $usdEquivalent,
                            // A bulk file is brought in by an admin and stands as its own
                            // approval; the desk is there so single bookings get checked.
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
                    });

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

    /**
     * Identity of a transfer for duplicate spotting: who sent it, who receives it, and how much.
     * Phones are compared in their normalized shape so 0772..., +256772... and 256772... match.
     */
    private function importMatchKey(?string $sender, ?string $receiver, ?string $currency, float|string $amount): string
    {
        return implode('|', [
            User::normalizePhone($sender) ?? '',
            User::normalizePhone($receiver) ?? '',
            strtoupper(trim((string) $currency)),
            rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.'),
        ]);
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

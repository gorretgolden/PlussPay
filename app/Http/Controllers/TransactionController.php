<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ProfitCalculator;
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
        $viewer = $request->user();

        $transactions = Transaction::with('creator')
            ->visibleTo($viewer)
            ->filtered($request)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        // The dropdown offers the names the list can actually be cut to, so a desk that only
        // books its own work sees itself and nobody else's.
        $creators = User::whereHas('transactions', fn ($query) => $query->visibleTo($viewer))
            ->orderBy('name')
            ->get();

        // The price dropdowns list only the figures this desk has really booked at, newest
        // price first, so a set-aside rate cannot offer a page of empty results. A viewer who
        // cannot see profit never sees the desk's prices either, and never pays for the queries.
        $usdPrices = $rmbPrices = [];

        if ($viewer->can('view-profit')) {
            $usdPrices = Transaction::bookedPrices($viewer, ['rate_buy_ugx', 'rate_sell_ugx']);
            $rmbPrices = Transaction::bookedPrices($viewer, ['rmb_buying_rate', 'exchange_rate']);
        }

        return view('transactions.index', compact('transactions', 'creators', 'usdPrices', 'rmbPrices'));
    }

    public function create()
    {
        $usdUgx = ExchangeRate::usdUgxPair();
        $usdRmb = ExchangeRate::pair('USD', 'RMB');

        // One-time token so a replayed or double-posted submit cannot mint a second receipt.
        $formId = (string) Str::uuid();
        session(['transaction_form_id' => $formId]);

        // One flat figure from Settings opens the charge box. It is a starting point only:
        // the cashier types the real charge for each customer, and zero opens the box empty.
        $default = (float) \App\Models\Setting::get('default_charge_ugx', 0);
        $defaultCharge = $default > 0 ? rtrim(rtrim(number_format($default, 2, '.', ''), '0'), '.') : '';

        return view('transactions.create', compact('usdUgx', 'usdRmb', 'formId', 'defaultCharge'));
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
            'charge_ugx' => "required|numeric|min:0|max:{$maxAmount}|decimal:0,2",
            'purpose' => 'nullable|string|max:100',
            'purpose_other' => 'required_if:purpose,Other|nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ], [
            'form_id.required' => 'This form has expired. Please reload the page and try again.',
            'amount.min' => "Minimum amount is {$minAmount}.",
            'amount.max' => "Maximum amount is ".number_format($maxAmount).".",
            'amount.decimal' => 'Amount cannot have more than 2 decimal places.',
            'charge_ugx.required' => 'Enter the charge taken out of the money handed over.',
            'charge_ugx.numeric' => 'The charge must be a number of shillings.',
            'charge_ugx.min' => 'The charge cannot be negative.',
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

        if (! ExchangeRate::getActiveRate('USD', 'RMB')) {
            return back()->withErrors(['exchange_rate' => 'No price is in use for USD to RMB. Set it in System Settings, or bring an earlier price back on the Currency Rates page.']);
        }

        if (! ExchangeRate::usdUgxPair()) {
            return back()->withErrors(['exchange_rate' => 'No price is in use for USD to UGX. Set it in System Settings, or bring an earlier price back on the Currency Rates page.']);
        }

        // The browser only ever shows a preview. Every figure that lands in the books, including
        // the money that travels and the yuan handed to the receiver, is priced here instead.
        $quote = ProfitCalculator::quote($validated['currency'], (string) $validated['amount'], (string) $validated['charge_ugx']);

        if ($quote === null) {
            return back()->withErrors(['charge_ugx' => 'The charge leaves nothing to send. It must be less than the money handed over.'])->withInput();
        }

        $purpose = $validated['purpose'] ?? null;
        if ($purpose === 'Other') {
            $purpose = trim($validated['purpose_other'] ?? '') ?: 'Other';
        }

        // The booking and the ledger entries it posts save together, so a failure part way
        // through cannot leave a transfer in the books with no money moved for it.
        $transaction = DB::transaction(fn () => Transaction::createWithReceiptNumber(array_merge([
            'sender_name' => trim($validated['sender_name']),
            'sender_contact' => trim($validated['sender_contact'] ?? ''),
            'receiver_name' => trim($validated['receiver_name']),
            'receiver_contact' => trim($validated['receiver_contact'] ?? ''),
            'currency' => $validated['currency'],
            'status' => 'completed',
            'created_by' => auth()->id(),
            'purpose' => $purpose ? trim($purpose) : null,
            'notes' => !empty($validated['notes']) ? trim($validated['notes']) : null,
        ], $quote)));

        session(['transaction_form_id' => (string) Str::uuid()]);

        ActivityLog::create([
            'transaction_id' => $transaction->id,
            'user_id' => auth()->id(),
            'action' => 'created',
            'description' => 'Transaction created',
            'new_value' => $transaction->status,
        ]);

        return redirect()->route('receipts.show', $transaction->receipt_number)
            ->with('success', $this->statusFlash($transaction->status, $transaction));
    }

    public function show(Request $request, Transaction $transaction)
    {
        abort_unless($this->canView($request, $transaction), 403, 'You can only open transactions you created.');

        $transaction->load('activityLogs.user');

        // The till side of a transfer posts itself the moment it is completed, but the payout in
        // China is a separate piece of cash that only the China desk knows has left. Both sides are
        // listed here so the account that went up is never shown without the one that came down.
        $legs = AccountEntry::with(['account', 'counterparty'])
            ->where('reference', $transaction->receipt_number)
            ->orWhere('transaction_id', $transaction->id)
            ->orderBy('direction')
            ->orderBy('account_id')
            ->get();

        return view('transactions.show', [
            'transaction' => $transaction,
            'legs' => $legs,
            'payoutAccount' => Account::byCode(Account::RMB_CHINA),
            'payoutRecorded' => $legs->contains('direction', 'out'),
        ]);
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

    private function canView(Request $request, Transaction $transaction): bool
    {
        return $transaction->created_by === $request->user()->id
            || $request->user()->can('view-all-transactions');
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
            if (! ExchangeRate::usdUgxPair()) {
                return back()->withErrors(['file' => 'UGX rate not set. The transfer cannot be priced.']);
            }
            $imported = 0;
            $errors = [];

            // Uploading the same file twice used to book every transfer a second time, so any
            // row that repeats the file or the last week of bookings is rejected.
            $seenInFile = [];
            $bookedRecently = Transaction::where('created_at', '>=', now()->subDays(7))
                ->get(['receipt_number', 'sender_contact', 'receiver_contact', 'currency', 'amount', 'charge_ugx', 'created_at'])
                ->keyBy(fn ($t) => $this->importMatchKey($t->sender_contact, $t->receiver_contact, $t->currency, $t->cashReceived()));

            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;

                $checked = Validator::make($row, [
                    'sender_name' => 'required|string|max:100|min:2',
                    'sender_contact' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
                    'receiver_name' => 'required|string|max:100|min:2',
                    'receiver_contact' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
                    'currency' => 'required|in:UGX,USD',
                    'amount' => "required|numeric|min:{$minAmount}|max:{$maxAmount}",
                    'charge_ugx' => 'nullable|numeric|min:0|max:'.$maxAmount,
                    'purpose' => 'nullable|string|max:100',
                    'notes' => 'nullable|string|max:500',
                ]);

                if ($checked->fails()) {
                    $errors[] = "Row {$rowNumber}: " . implode(' ', $checked->errors()->all());
                    continue;
                }

                $data = $checked->validated();
                $currency = strtoupper(trim($data['currency']));
                $cashReceived = (string) $data['amount'];

                // The charge is agreed with each customer, so a blank cell is read as no charge
                // taken rather than a figure guessed from a rate card.
                $charge = isset($data['charge_ugx']) && $data['charge_ugx'] !== ''
                    ? (string) $data['charge_ugx']
                    : '0.00';

                $sender = trim($data['sender_contact'] ?? '');
                $receiver = trim($data['receiver_contact'] ?? '');
                $matchKey = $this->importMatchKey($sender, $receiver, $currency, $cashReceived);

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

                $quote = ProfitCalculator::quote($currency, $cashReceived, $charge);
                if ($quote === null) {
                    $errors[] = "Row {$rowNumber}: the charge of {$charge} leaves nothing to send out of {$cashReceived}.";
                    continue;
                }

                try {
                    // One transaction per row: the booking and the ledger entries it posts are
                    // either all saved or none of them are, so a bad row cannot leave half a
                    // transfer in the books.
                    DB::transaction(function () use ($data, $sender, $receiver, $currency, $quote) {
                        $transaction = Transaction::createWithReceiptNumber(array_merge([
                            'sender_name' => trim($data['sender_name']),
                            'sender_contact' => $sender,
                            'receiver_name' => trim($data['receiver_name']),
                            'receiver_contact' => $receiver,
                            'currency' => $currency,
                            'status' => 'completed',
                            'created_by' => auth()->id(),
                            'purpose' => !empty($data['purpose']) ? trim($data['purpose']) : null,
                            'notes' => !empty($data['notes']) ? trim($data['notes']) : null,
                        ], $quote));

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

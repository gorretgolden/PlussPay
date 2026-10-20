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
use Illuminate\Validation\Rule;

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

        // The price dropdowns list only the selling figures this desk has really booked at, newest
        // price first, so a set-aside rate cannot offer a page of empty results. A viewer who
        // cannot see profit never sees the desk's prices either, and never pays for the queries.
        $usdPrices = $rmbPrices = [];

        if ($viewer->can('view-profit')) {
            $usdPrices = Transaction::bookedPrices($viewer, ['rate_sell_ugx']);
            $rmbPrices = Transaction::bookedPrices($viewer, ['exchange_rate']);
        }

        // The money cut by desk, read off the same filters the list below is read off, so the
        // shillings standing under Uganda and the yuan standing under China describe the rows a
        // viewer is actually looking at and not some other slice of the book.
        $deskTotals = Transaction::visibleTo($viewer)->filtered($request)->deskTotals();

        return view('transactions.index', compact('transactions', 'creators', 'usdPrices', 'rmbPrices', 'deskTotals'));
    }

    public function create(Request $request)
    {
        $usdUgx = ExchangeRate::usdUgxPair();
        $usdRmb = ExchangeRate::pair('USD', 'RMB');

        // The dashboard opens this form through one of two cards, so which way the money is going
        // arrives on the query string. Anything else falls back to the corridor the desk has always
        // run: cash in Uganda, a receiver in China.
        $destination = $request->input('destination') === Transaction::TO_UGANDA
            ? Transaction::TO_UGANDA
            : Transaction::TO_CHINA;

        // One-time token so a replayed or double-posted submit cannot mint a second receipt.
        $formId = (string) Str::uuid();
        session(['transaction_form_id' => $formId]);

        // One flat figure from Settings opens the charge box. It is a starting point only:
        // the cashier types the real charge for each customer, and zero opens the box empty.
        $default = (float) \App\Models\Setting::get('default_charge_ugx', 0);
        $defaultCharge = $default > 0 ? rtrim(rtrim(number_format($default, 2, '.', ''), '0'), '.') : '';

        return view('transactions.create', compact('usdUgx', 'usdRmb', 'formId', 'defaultCharge', 'destination') + [
            'agents' => $this->bookingAgents(),
        ]);
    }

    /**
     * The hands an office booking can be put into: everybody the book keeps as an agent, on either
     * desk, because the office is the one place that sees both. Only a person who sees the whole
     * book may book into somebody else's name, and a closed account is not a hand anyone can put
     * money into. The desk travels with the name so the two are never confused on the way past.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function bookingAgents(): \Illuminate\Support\Collection
    {
        $viewer = auth()->user();

        if (! $viewer || ! $viewer->can('view-all-transactions')) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->role(['agent', 'super-agent'])
            ->orderByRaw('coalesce(country, ?) = ? desc, name asc', [User::DEFAULT_COUNTRY, User::DEFAULT_COUNTRY])
            ->get(['id', 'name', 'country']);
    }

    public function store(Request $request)
    {
        $minAmount = (float) \App\Models\Setting::get('min_transaction_amount', 100);
        $maxAmount = (float) \App\Models\Setting::get('max_transaction_amount', 100000000);

        // Only an agent's hand holds this kind of cash, so a transfer is always written in an
        // agent's name. Somebody signing in at their own desk is their own agent; anyone who can
        // see the whole book is standing at a counter taking money for other people, so they have
        // to say whose before the booking will save.
        $mustNameHolder = (bool) auth()->user()?->can('view-all-transactions');

        // Which way the money is going decides which money this desk can take in and which money it
        // hands out, so the route is read before the rest of the form is checked. A booking to China
        // is never taken in from China, and a booking to Uganda never pays out yuan. The wizard always
        // answers both, and a booking that arrives without them is read as the corridor this desk has
        // run since it opened, priced in yuan at the far end.
        $toUganda = $request->input('destination') === Transaction::TO_UGANDA;
        $intakeChoices = $toUganda ? ['RMB', 'USD'] : ['UGX', 'USD'];
        $payoutChoices = $toUganda ? ['UGX'] : ['RMB', 'USD'];

        $validated = $request->validate([
            'form_id' => 'required|string',
            'sender_name' => 'required|string|max:100|min:2',
            'sender_contact' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'receiver_name' => 'required|string|max:100|min:2',
            'receiver_contact' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'destination' => ['nullable', Rule::in([Transaction::TO_CHINA, Transaction::TO_UGANDA])],
            'currency' => ['required', Rule::in($intakeChoices)],
            'payout_currency' => ['nullable', Rule::in($payoutChoices)],
            'amount' => "required|numeric|min:{$minAmount}|max:{$maxAmount}|decimal:0,2",
            'charge_ugx' => "required|numeric|min:0|max:{$maxAmount}|decimal:0,2",
            'purpose' => 'nullable|string|max:100',
            'purpose_other' => 'required_if:purpose,Other|nullable|string|max:100',
            'payment_method' => ['required', Rule::in(Transaction::PAYMENT_METHODS)],
            'payment_reference' => ['required_unless:payment_method,'.Transaction::CASH_PAYMENT, 'nullable', 'string', 'max:60'],
            'notes' => 'nullable|string|max:500',
            'agent_id' => $mustNameHolder ? 'required' : 'nullable',
        ], [
            'form_id.required' => 'This form has expired. Please reload the page and try again.',
            'agent_id.required' => 'Say which agent took this money. A transfer always belongs to the agent holding the cash, so the office has to name them before it books.',
            'destination.in' => 'Money either goes to a receiver in China or to a receiver in Uganda. Choose one of those two.',
            'currency.required' => 'Say what money was handed over.',
            'currency.in' => $toUganda
                ? 'On the way to Uganda the money is handed over in China, in yuan or dollars. Shillings are not what a customer there pays with.'
                : 'On the way to China the money is handed over here in Uganda, in shillings or dollars.',
            'payout_currency.in' => $toUganda
                ? 'A receiver in Uganda is handed UGX shillings.'
                : 'A receiver in China is handed either RMB yuan or USD dollars.',
            'amount.min' => "Minimum amount is {$minAmount}.",
            'amount.max' => "Maximum amount is ".number_format($maxAmount).".",
            'amount.decimal' => 'Amount cannot have more than 2 decimal places.',
            'charge_ugx.required' => 'Enter the charge taken out of the money handed over.',
            'charge_ugx.numeric' => 'The charge must be a number of shillings.',
            'charge_ugx.min' => 'The charge cannot be negative.',
            'purpose_other.required_if' => 'Please say what the other reason is.',
            'payment_method.required' => 'Say how the customer paid you, so the money in the till matches the way it arrived.',
            'payment_method.in' => 'That is not one of the ways this desk takes money. Choose Cash, MTN Mobile Money, Airtel Money, Bank transfer, or Other.',
            'payment_reference.required_unless' => 'Write the confirmation number from the payment alert, such as the MoMo transaction ID or the bank reference. Cash is the only payment that needs none.',
            'payment_reference.max' => 'The confirmation number is at most 60 characters.',
            'sender_name.required' => 'Sender name is required.',
            'sender_name.min' => 'Sender name must be at least 2 characters.',
            'sender_contact.regex' => $toUganda
                ? 'Enter a valid phone number (e.g. +86 138 0000 0000), which is the number of whoever hands the money over in China.'
                : 'Enter a valid phone number (e.g. +256 700 000 000).',
            'receiver_name.required' => 'Receiver name is required.',
            'receiver_name.min' => 'Receiver name must be at least 2 characters.',
            'receiver_contact.regex' => $toUganda
                ? 'Enter a valid phone number (e.g. +256 700 000 000), which is the number of whoever is paid here in Uganda.'
                : 'Enter a valid phone number (e.g. +86 138 0000 0000).',
            'amount.required' => 'Amount is required.',
        ]);

        // The wizard answers the route and the payout every time, so a booking that arrives without
        // them is one keyed in from somewhere else, and it is read as the corridor this desk has run
        // since it opened with the money that route has always handed over.
        $validated['destination'] ??= $toUganda ? Transaction::TO_UGANDA : Transaction::TO_CHINA;
        $validated['payout_currency'] ??= $payoutChoices[0];

        if (! hash_equals((string) session('transaction_form_id', ''), $validated['form_id'])) {
            return back()->withErrors(['form_id' => 'This transaction was already submitted. Start a new form to send another.']);
        }

        // Whichever agent is named here is the one the books are written into: the transfer shows
        // on their own list, their wallet holds the cash until it is handed in, and the charge is
        // their commission. A name that is not on this desk, or one replayed by somebody who may
        // not see the whole book, is refused here rather than quietly accepted from the page.
        // The office counter is the one answer that is not a person: the till holds that money.
        $holder = null;
        $choice = (string) ($validated['agent_id'] ?? '');

        if ($choice !== '' && $choice !== 'counter') {
            $holder = $this->bookingAgents()->firstWhere('id', (int) $choice);

            if (! $holder) {
                return back()->withErrors([
                    'agent_id' => 'No such agent to book for. Only an open agent on either desk can be chosen, and only by someone who sees every desk\'s bookings.',
                ])->withInput();
            }
        }

        // Only the prices this corridor actually reads are demanded, so a booking that pays dollars
        // out in China is never blocked because no yuan price is set, and a dollar booking from
        // China needs no yuan price either.
        $missing = collect(ProfitCalculator::requiredRatePairs(
            $validated['currency'],
            $validated['destination'],
            $validated['payout_currency'],
        ))
            ->reject(fn (array $pair) => (bool) ExchangeRate::pair($pair[0], $pair[1]))
            ->map(fn (array $pair) => $pair[0].' to '.$pair[1])
            ->values();

        if ($missing->isNotEmpty()) {
            return back()->withErrors([
                'exchange_rate' => 'No price is in use for '.$missing->implode(', ').
                    '. Set it in System Settings, or bring an earlier price back on the Currency Rates page.',
            ])->withInput();
        }

        // The browser only ever shows a preview. Every figure that lands in the books, including
        // the money that travels and the cash handed to the receiver, is priced here instead.
        $quote = ProfitCalculator::quote(
            $validated['currency'],
            (string) $validated['amount'],
            (string) $validated['charge_ugx'],
            $validated['destination'],
            $validated['payout_currency'],
        );

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
            'created_by' => $holder?->id ?? auth()->id(),
            'purpose' => $purpose ? trim($purpose) : null,
            'payment_method' => $validated['payment_method'],
            'payment_reference' => !empty($validated['payment_reference']) ? trim($validated['payment_reference']) : null,
            'notes' => !empty($validated['notes']) ? trim($validated['notes']) : null,
        ], $quote)));

        session(['transaction_form_id' => (string) Str::uuid()]);

        ActivityLog::create([
            'transaction_id' => $transaction->id,
            'user_id' => auth()->id(),
            'action' => 'created',
            'description' => $holder
                ? 'Transaction created for '.$holder->name.', booked by '.(auth()->user()->name ?? 'the office')
                : 'Transaction created',
            'new_value' => $transaction->status,
        ]);

        return redirect()->route('receipts.show', $transaction->receipt_number)
            ->with('success', $this->statusFlash($transaction->status, $transaction)
                .($holder ? ' Booked in the name of '.$holder->name.'.' : ''));
    }

    public function show(Request $request, Transaction $transaction)
    {
        abort_unless($this->canView($request, $transaction), 403, 'You can only open transactions you created.');

        $transaction->load('activityLogs.user');

        // The till side of a transfer posts itself the moment it is completed, but the money handed
        // over at the far end is a separate piece of cash that only the desk there knows has left.
        // Both sides are listed here so the account that went up is never shown without the one that
        // came down.
        $legs = AccountEntry::with(['account', 'counterparty'])
            ->where('reference', $transaction->receipt_number)
            ->orWhere('transaction_id', $transaction->id)
            ->orderBy('direction')
            ->orderBy('account_id')
            ->get();

        // The receiver can be paid in yuan, dollars or shillings depending on the route, and each of
        // those is a different wallet of the book. The payout is read off the transfer's own promise
        // so a desk that pays a receiver in dollars is never shown a yuan account to write it against.
        $payoutCurrency = $transaction->payoutCurrency();
        $payoutDesk = $transaction->settledDesk;

        return view('transactions.show', [
            'transaction' => $transaction,
            'legs' => $legs,
            // Money comes out of the wallet of the very desk that was sent the dollars for it, and
            // falls back to the company book of that money when no desk is named.
            'payoutAccount' => $payoutDesk
                ? Account::walletFor($payoutDesk, $payoutCurrency)
                : Account::byCode(match ($payoutCurrency) {
                    'USD' => Account::USD_CHINA,
                    'UGX' => Account::UGX_UGANDA,
                    default => Account::RMB_CHINA,
                }),
            // Only cash paid out of the account holding the money the receiver was promised is the
            // payout. A dollar line on the same receipt is the other half of the booking or the
            // dollars sent across, and a transfer out of the till is not money handed to anybody.
            'payoutRecorded' => $legs->contains(fn (AccountEntry $leg) => $leg->type === 'money_out'
                && $leg->account?->currency === $payoutCurrency),
            'stage' => $transaction->stage(),
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

        // Once the dollars for a transfer have been sent to a desk in China, the cash this office
        // holds no longer covers it: the yuan is either in China waiting or already handed over.
        // Cancelling from here would take back money that is not here any more.
        if ($newStatus === 'cancelled' && $transaction->hasLeftUganda()) {
            return back()->withErrors([
                'status' => $transaction->receipt_number.' has already been sent to China'
                    .($transaction->delivered_at ? ' and paid to '.$transaction->receiver_name : '')
                    .', so it cannot be cancelled from here. The desk in China has to bring the money back first.',
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

                // A file that says nothing about how the money arrived is a sheet of counter
                // bookings, so the blank is read as cash before the row is checked. Doing it here
                // rather than after the check is what keeps an old file with no such column
                // importing: a row with no answer at all would otherwise be asked for a
                // confirmation number it has no way to supply.
                $row['payment_method'] = isset($row['payment_method']) && trim((string) $row['payment_method']) !== ''
                    ? trim((string) $row['payment_method'])
                    : Transaction::CASH_PAYMENT;

                $checked = Validator::make($row, [
                    'sender_name' => 'required|string|max:100|min:2',
                    'sender_contact' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
                    'receiver_name' => 'required|string|max:100|min:2',
                    'receiver_contact' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
                    'currency' => 'required|in:UGX,USD',
                    'amount' => "required|numeric|min:{$minAmount}|max:{$maxAmount}",
                    'charge_ugx' => 'nullable|numeric|min:0|max:'.$maxAmount,
                    'purpose' => 'nullable|string|max:100',
                    'payment_method' => ['required', Rule::in(Transaction::PAYMENT_METHODS)],
                    'payment_reference' => ['required_unless:payment_method,'.Transaction::CASH_PAYMENT, 'nullable', 'string', 'max:60'],
                    'notes' => 'nullable|string|max:500',
                ], [
                    'payment_method.in' => 'payment_method must be Cash, MTN Mobile Money, Airtel Money, Bank transfer, or Other.',
                    'payment_reference.required_unless' => 'payment_reference holds the confirmation number, which every payment that is not cash has to carry.',
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
                            // A row that says nothing is read as cash, which is what a sheet of
                            // counter bookings is. Correct it by rebooking if it was not.
                            'payment_method' => $data['payment_method'],
                            'payment_reference' => !empty($data['payment_reference']) ? trim($data['payment_reference']) : null,
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

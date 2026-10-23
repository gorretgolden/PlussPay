<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\CashHandover;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The link between a minor agent and the super agent who sends the money on. The agent asks, the
 * super agent takes the cash in hand; only then do the two wallets move. Until that moment the
 * books still say the cash is in the agent's drawer, because it is.
 */
class CashHandoverController extends Controller
{
    /** The states a desk can filter the list by, in the order she reads them. */
    private const STATUS_CHOICES = [
        CashHandover::PENDING,
        CashHandover::APPROVED,
        CashHandover::DECLINED,
        CashHandover::CANCELLED,
    ];

    /** The labels of the same states, because "Sent back" is what she says out loud. */
    public const STATUS_LABELS = [
        CashHandover::PENDING => 'Pending',
        CashHandover::APPROVED => 'Approved',
        CashHandover::DECLINED => 'Declined',
        CashHandover::CANCELLED => 'Cancelled by the agent',
    ];

    /** Period presets. The key comes off the query string, the range is worked out here. */
    public const PERIOD_CHOICES = [
        'all' => 'All time',
        'today' => 'Today',
        'week' => 'This week',
        'month' => 'This month',
        'last_month' => 'Last month',
        'year' => 'This year',
    ];

    public const CURRENCY_CHOICES = ['UGX', 'USD', 'RMB'];

    /**
     * Every desk reads its own requests and the office that holds the cash reads all of them. The
     * scope is decided from the signed-in account alone: what the address bar adds can only ever
     * cut that set further, never open a door on it.
     */
    public function index(Request $request)
    {
        $viewer = $request->user();
        $everyone = $viewer->can('view-all-wallets');
        [$from, $to] = $this->window($request);

        $handovers = $this->filtered($request, $viewer, $from, $to)->get();

        // Counted from the rows on the screen rather than a second query, so the strip above the
        // table can never disagree with the table under it. The currencies stay apart: shillings,
        // dollars and yuan are not one total. The charges are counted in their own column because
        // they are the money that never leaves Uganda, whatever the cash beside them is sent for.
        $totals = $handovers->reduce(function (array $carry, CashHandover $handover) {
            $code = strtoupper($handover->currency);
            $carry[$code] ??= ['count' => 0, 'amount' => '0', 'charges' => '0'];
            $carry[$code]['count']++;
            $carry[$code]['amount'] = bcadd($carry[$code]['amount'], (string) $handover->amount, 2);
            $carry[$code]['charges'] = bcadd($carry[$code]['charges'], (string) $handover->charge_ugx, 2);

            return $carry;
        }, []);

        // Only the office is offered the narrowing, and only over desks that have actually handed
        // cash in, so the list of names is never a copy of the whole people book.
        $agentChoices = $everyone
            ? User::whereIn('id', CashHandover::query()->select('agent_id')->distinct()->pluck('agent_id'))
                ->orderBy('name')->get()
            : collect();

        return view('cash-handovers.index', [
            'handovers' => $handovers,
            'everyone' => $everyone,
            'totals' => $totals,
            'agentChoices' => $agentChoices,
            'dateFrom' => $from?->toDateString(),
            'dateTo' => $to?->toDateString(),
            'canDecide' => $viewer->can('approve-cash-handover'),
        ]);
    }

    /**
     * The dates the list is cut to. A preset gives the pair, and a date typed into a box replaces
     * the end of it it names. Both ends come back as Carbon or null so the table can print the
     * window it actually used.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function window(Request $request): array
    {
        $now = Carbon::now();
        $period = (string) $request->input('period', 'all');

        $range = match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            default => [null, null],
        };

        return [
            $this->dateOrNull($request->input('date_from')) ?? $range[0],
            $this->dateOrNull($request->input('date_to')) ?? $range[1],
        ];
    }

    private function dateOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * One query for the page and for the export, so the file is the same set the screen is showing.
     * The scope clause is applied before anything off the query string, and the dates go through
     * whereDate because a plain between on a datetime column drops the last day of the window.
     */
    private function filtered(Request $request, User $viewer, ?Carbon $from, ?Carbon $to): Builder
    {
        $query = CashHandover::query()->with(['agent', 'superAgent', 'transactions']);

        if ($viewer->can('view-all-wallets')) {
            if ($request->filled('agent_id')) {
                $query->where('agent_id', (int) $request->input('agent_id'));
            }
        } else {
            // A desk reads what it handed in and what was handed to it, and nothing else. Typing
            // another person's id into the address bar changes nothing on this branch.
            $query->where(fn (Builder $own) => $own->where('agent_id', $viewer->id)
                ->orWhere('super_agent_id', $viewer->id));
        }

        if (in_array((string) $request->input('status'), self::STATUS_CHOICES, true)) {
            $query->where('status', $request->input('status'));
        }

        if (in_array(strtoupper((string) $request->input('currency')), self::CURRENCY_CHOICES, true)) {
            $query->where('currency', strtoupper((string) $request->input('currency')));
        }

        if ($request->filled('search')) {
            $term = '%'.addcslashes((string) $request->input('search'), '%_\\').'%';
            $query->where(fn (Builder $any) => $any->where('reference', 'like', $term)
                ->orWhereHas('agent', fn (Builder $agent) => $agent->where('name', 'like', $term)));
        }

        if ($from) {
            $query->whereDate('created_at', '>=', $from->toDateString());
        }

        if ($to) {
            $query->whereDate('created_at', '<=', $to->toDateString());
        }

        return $query->latest('created_at')->latest('id');
    }

    /**
     * The same sheet as the screen: the scope is taken from the signed-in account and every filter
     * box is honoured, so a narrowed page exports the narrow set rather than the whole book.
     */
    public function exportExcel(Request $request)
    {
        $viewer = $request->user();
        [$from, $to] = $this->window($request);

        $handovers = $this->filtered($request, $viewer, $from, $to)->get();

        ActivityLog::create([
            'user_id' => $viewer->id,
            'action' => 'exported',
            'description' => $viewer->name.' exported '.number_format($handovers->count())
                .' cash hand in request(s).',
            'new_value' => (string) $handovers->count(),
        ]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cash Hand Ins');

        $headers = ['Reference', 'Date', 'Agent', 'Super agent', 'Currency', 'Amount', 'As shown',
            'Charges handed in', 'Transfers named', 'Status', 'Their note', 'Decision note', 'Decided at'];

        foreach ($headers as $index => $header) {
            $sheet->setCellValue(chr(ord('A') + $index).'1', $header);
        }

        $sheet->getStyle('A1:M1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '14532D']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $row = 2;
        foreach ($handovers as $handover) {
            // Text cells are forced to TYPE_STRING so a name starting with =, + or @
            // cannot become an executable formula when the sheet is opened.
            $this->putText($sheet, 'A'.$row, (string) $handover->reference);
            $this->putText($sheet, 'B'.$row, $handover->created_at?->format('Y-m-d h:i A') ?? '');
            $this->putText($sheet, 'C'.$row, (string) ($handover->agent?->name ?? ''));
            $this->putText($sheet, 'D'.$row, (string) ($handover->superAgent?->name ?? ''));
            $this->putText($sheet, 'E'.$row, strtoupper((string) $handover->currency));
            $sheet->setCellValue('F'.$row, (float) $handover->amount);
            $this->putText($sheet, 'G'.$row, $handover->figure());
            $sheet->setCellValue('H'.$row, (float) $handover->charge_ugx);
            $this->putText($sheet, 'I'.$row, $handover->receiptNumbers()->implode(', '));
            $this->putText($sheet, 'J'.$row, (string) (self::STATUS_LABELS[$handover->status] ?? ucfirst((string) $handover->status)));
            $this->putText($sheet, 'K'.$row, (string) ($handover->note ?? ''));
            $this->putText($sheet, 'L'.$row, (string) ($handover->decision_note ?? ''));
            $this->putText($sheet, 'M'.$row, $handover->decided_at?->format('Y-m-d h:i A') ?? '');
            $row++;
        }

        if ($row > 2) {
            $sheet->getStyle('F2:F'.($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('H2:H'.($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('A2:M'.($row - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
        }

        foreach (range('A', 'M') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $filename = 'PlusPay_Cash_Hand_Ins_'
            .($from?->toDateString() ?? 'all').'_to_'.($to?->toDateString() ?? 'today').'.xlsx';

        return response()->streamDownload(
            fn () => (new Xlsx($spreadsheet))->save('php://output'),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    private function putText($sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    /**
     * Approving a stack of hand-ins in one go. The reach is exactly the single decision's reach:
     * only the super agent a request was addressed to may take it, so a ticked row that is not
     * theirs is counted as skipped rather than quietly decided or dropped.
     */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|string|in:approve',
            'handover_ids' => 'required|array|min:1|max:200',
            'handover_ids.*' => 'integer|distinct|exists:cash_handovers,id',
        ], [
            'handover_ids.required' => 'Tick at least one hand-in to approve.',
        ]);

        $viewer = $request->user();
        $changed = 0;
        $skips = [];

        $rows = CashHandover::with(['agent', 'superAgent'])
            ->whereIn('id', $validated['handover_ids'])
            ->orderBy('id')
            ->get();

        foreach ($rows as $handover) {
            if (! $handover->isPending()) {
                $skips['already decided'] = ($skips['already decided'] ?? 0) + 1;
                continue;
            }

            if (! $this->mayDecide($viewer, $handover)) {
                $skips['sent to another super agent'] = ($skips['sent to another super agent'] ?? 0) + 1;
                continue;
            }

            try {
                $moved = $handover->approve();
            } catch (InsufficientFundsException $e) {
                $skips[$e->getMessage()] = ($skips[$e->getMessage()] ?? 0) + 1;
                continue;
            }

            if (! $moved) {
                $skips['already decided'] = ($skips['already decided'] ?? 0) + 1;
                continue;
            }

            ActivityLog::create([
                'user_id' => $viewer->id,
                ...ActivityLog::subjectAttributes($handover),
                'action' => 'cash_handover_approved',
                'description' => $viewer->name.' received '.$handover->figure().' from '
                    .$handover->agent->name.' ('.$handover->reference.') in a bulk approval.',
                'new_value' => json_encode([
                    'reference' => $handover->reference,
                    'amount' => $handover->figure(),
                    'agent' => $handover->agent->name,
                    'super_agent' => $viewer->name,
                    'bulk' => true,
                ]),
            ]);

            $changed++;
        }

        $message = $changed > 0
            ? 'Approved '.$changed.' hand-in'.($changed === 1 ? '' : 's').'.'
            : 'Nothing was changed.';

        if ($skips) {
            $parts = [];
            foreach ($skips as $reason => $count) {
                $parts[] = number_format($count).' '.$reason;
            }
            $message .= ' Skipped '.implode(', ', $parts). '.';
        }

        return $changed > 0
            ? back()->with('success', $message)
            : back()->withErrors(['handover' => $message]);
    }

    /**
     * The usual way to hand cash in: name the transfers whose money is in front of you and send
     * those names up, rather than a figure nobody can check. The request carries the whole of what
     * those bookings are worth, which is the cash they put in your hand plus the charges they earned,
     * because both of those piles are sitting in your drawer.
     *
     * Nothing here trusts the figures the page drew. The transfers are re-read from the book and the
     * totals worked out again, so a stale tab or a tampered form cannot hand in money that is not
     * there or leave a booking out of the count.
     */
    public function storeFromTransactions(Request $request)
    {
        $viewer = $request->user();

        if ($message = $this->refusalFor($viewer)) {
            return back()->withErrors(['super_agent_id' => $message]);
        }

        $validated = $request->validate([
            'super_agent_id' => 'required|integer',
            'transaction_ids' => 'required|array|min:1|max:200',
            'transaction_ids.*' => 'integer|distinct',
            'note' => 'nullable|string|max:255',
        ], [
            'super_agent_id.required' => 'Choose the super agent you are handing the cash to.',
            'transaction_ids.required' => 'Choose the transfer whose cash you are handing in.',
            'transaction_ids.min' => 'Choose at least one transfer.',
        ]);

        $superAgent = User::find($validated['super_agent_id']);

        if (! $superAgent || ! $superAgent->hasRole('super-agent') || ! $superAgent->is_active) {
            return back()->withErrors(['super_agent_id' => 'Choose an active super agent.'])->withInput();
        }

        $ids = collect($validated['transaction_ids'])->map(fn ($id) => (int) $id)->unique()->values();

        // Only the hand that took the cash can hand it in, so a typed list of ids buys nothing: the
        // rows are cut to this person's own bookings before anything is read off them.
        $bookings = Transaction::whereIn('id', $ids->all())
            ->where('created_by', $viewer->id)
            ->orderBy('receipt_number')
            ->lockForUpdate()
            ->get();

        if ($bookings->count() !== $ids->count()) {
            return back()->withErrors([
                'transaction_ids' => 'Some of those transfers are not yours to hand in. Reload the list and'
                    .' choose them again.',
            ])->withInput();
        }

        $notFinished = $bookings->reject(fn (Transaction $t) => $t->status === 'completed');

        if ($notFinished->isNotEmpty()) {
            return back()->withErrors([
                'transaction_ids' => 'These transfers carry no cash to hand in: '.$this->receiptList($notFinished)
                    .'. A cancelled booking gave its money back to the sender, and one still pending approval'
                    .' has not been taken in yet.',
            ])->withInput();
        }

        $spokenFor = $bookings->filter(fn (Transaction $t) => $t->cash_handover_id !== null);

        if ($spokenFor->isNotEmpty()) {
            return back()->withErrors([
                'transaction_ids' => 'These transfers are already named on a hand-in: '.$this->receiptList($spokenFor)
                    .'. Cancel that request ('.$spokenFor->first()->cashHandover?->reference.') first, or hand in other transfers.',
            ])->withInput();
        }

        $currencies = $bookings->pluck('currency')->map(strtoupper(...))->unique()->values();

        if ($currencies->count() > 1) {
            return back()->withErrors([
                'transaction_ids' => 'One hand-in carries one money. You named '
                    .$currencies->implode(', ').' bookings, so send them as one hand-in per money.',
            ])->withInput();
        }

        $currency = $currencies->first();

        if (! in_array($currency, Account::currenciesFor($viewer), true)) {
            return back()->withErrors(['currency' => "Your desk does not hold {$currency}."])->withInput();
        }

        if (! in_array($currency, Account::currenciesFor($superAgent), true)) {
            return back()->withErrors([
                'super_agent_id' => $superAgent->name.' does not hold a '.$currency.' wallet.',
            ])->withInput();
        }

        $principal = $bookings->reduce(fn (string $carry, Transaction $t) => bcadd($carry, (string) $t->amount, 2), '0.00');

        // A booking into Uganda never had its charge collected as money, so no shillings for it are
        // sitting in the drawer to hand over. Counting one would ask the super agent to approve cash
        // that was never taken in.
        $charges = $bookings->reduce(
            fn (string $carry, Transaction $t) => $t->isToUganda()
                ? $carry
                : bcadd($carry, (string) $t->charge_ugx, 2),
            '0.00',
        );

        if ($short = $this->shortfall($viewer, $currency, $principal, $charges)) {
            return back()->withErrors(['transaction_ids' => $short])->withInput();
        }

        // Each request names its own transfers, so several can be in flight at once: a second
        // request cannot promise the same cash twice because the rows it names are already spoken
        // for. The request and the pointing of the bookings at it are written together, so the two
        // can never land apart.
        $handover = DB::transaction(function () use ($viewer, $superAgent, $currency, $principal, $request, $charges, $bookings) {
            $handover = CashHandover::request(
                $viewer,
                $superAgent,
                $currency,
                $principal,
                $request->input('note') ?: null,
                $charges,
                $bookings->pluck('receipt_number')->all(),
            );

            $handover->link($bookings->pluck('id')->all());

            return $handover;
        });

        ActivityLog::create([
            'user_id' => $viewer->id,
            ...ActivityLog::subjectAttributes($handover),
            'action' => 'cash_handover_requested',
            'description' => $viewer->name.' asked '.$superAgent->name.' to take '.$handover->figure()
                .' for '.$bookings->count().' transfer'.($bookings->count() === 1 ? '' : 's')
                .' ('.$handover->reference.'), plus '.$handover->chargeFigure().' in charges.',
            'new_value' => json_encode([
                'reference' => $handover->reference,
                'amount' => $handover->figure(),
                'charges' => $handover->chargeFigure(),
                'currency' => $currency,
                'agent' => $viewer->name,
                'super_agent' => $superAgent->name,
                'note' => $handover->note,
                'receipts' => $bookings->pluck('receipt_number')->implode(', '),
            ]),
        ]);

        return back()->with('success', 'Hand-in of '.$handover->figure().' for '.$bookings->count()
            .' transfer'.($bookings->count() === 1 ? '' : 's').' sent to '.$superAgent->name
            .($charges !== '0.00' ? ', with '.$handover->chargeFigure().' in charges kept back from it' : '')
            .', for approval. Reference '.$handover->reference.'.');
    }

    /**
     * The agent calls their own request back before anybody has decided it, which is what they do
     * when the transfers turn out to need something else first. Nothing moves: the bookings stop being
     * spoken for and go back to reading as cash the agent still holds.
     */
    public function cancel(Request $request, CashHandover $cashHandover)
    {
        $viewer = $request->user();

        if ($cashHandover->agent_id !== $viewer->id) {
            abort(403, 'Only the agent who raised a hand-in can cancel it.');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $bookings = $cashHandover->transactions()->count();

        if (! $cashHandover->cancel($validated['reason'] ?? null)) {
            return back()->withErrors([
                'handover' => 'That hand-in has already been approved or sent back, so it cannot be cancelled.',
            ]);
        }

        ActivityLog::create([
            'user_id' => $viewer->id,
            ...ActivityLog::subjectAttributes($cashHandover),
            'action' => 'cash_handover_cancelled',
            'description' => $viewer->name.' called back their own hand-in of '.$cashHandover->figure()
                .' ('.$cashHandover->reference.')'.($validated['reason'] ?? null ? ': '.$validated['reason'] : '').'.',
            'old_value' => json_encode(['status' => CashHandover::PENDING]),
            'new_value' => json_encode([
                'status' => CashHandover::CANCELLED,
                'reference' => $cashHandover->reference,
                'amount' => $cashHandover->figure(),
                'bookings' => $bookings,
            ]),
        ]);

        return back()->with('success', 'Hand-in '.$cashHandover->reference.' cancelled. '
            .($bookings > 0
                ? $bookings.' transfer'.($bookings === 1 ? '' : 's').' are back to reading as cash in your hand.'
                : 'The money stays in your wallet.'));
    }

    /**
     * Who is allowed to raise a hand-in at all. Cash travels up from an ordinary agent in Uganda to
     * the lead who sends it on; a lead already holds the cash he has been handed, and the desk in
     * China pays receivers out of what was sent to it, so neither has a hand-in to raise.
     */
    private function refusalFor(User $viewer): ?string
    {
        if (! $viewer->hasRole('agent') || $viewer->hasRole('super-agent')
            || ($viewer->country ?: User::DEFAULT_COUNTRY) === 'CN') {
            return 'Only an agent in Uganda hands cash in to a super agent.';
        }

        return null;
    }

    /**
     * The drawer has to actually hold both piles before a request is written down, because a hand-in
     * the super agent can never approve is worse than no hand-in at all.
     */
    private function shortfall(User $viewer, string $currency, string $principal, string $charges): ?string
    {
        $parts = [];

        $wallet = Account::walletFor($viewer, $currency);

        if (bccomp($principal, (string) $wallet->balance(), 2) > 0) {
            $parts[] = 'The '.$currency.' wallet holds '.Account::format($currency, $wallet->balance())
                .', but these transfers add up to '.Account::format($currency, $principal);
        }

        if (bccomp($charges, '0.00', 2) > 0) {
            $shillings = Account::walletFor($viewer, 'UGX');

            if (bccomp($charges, (string) $shillings->balance(), 2) > 0) {
                $parts[] = 'the shilling wallet holds '.Account::format('UGX', $shillings->balance())
                    .', but the charges on them add up to '.Account::format('UGX', $charges);
            }
        }

        return $parts === [] ? null : 'You cannot hand in more than your wallets hold: '.implode('; ', $parts).'.';
    }

    /** The first few receipts of a list, so a refusal names what it refused without running long. */
    private function receiptList($bookings): string
    {
        return $bookings->pluck('receipt_number')->take(6)->map(fn ($r) => (string) $r)->implode(', ')
            .($bookings->count() > 6 ? ' and '.($bookings->count() - 6).' more' : '');
    }

    /**
     * Whether this person may decide a request. The lead it was addressed to is the one holding the
     * cash, and the office that keeps the whole book may decide in his place when he is not at the
     * desk; nobody else reaches another desk's request at all.
     */
    private function mayDecide(User $viewer, CashHandover $handover): bool
    {
        return $handover->super_agent_id === $viewer->id || $viewer->can('view-all-wallets');
    }

    /**
     * The super agent confirms the cash is now in their hand. Only the person the request was
     * addressed to may decide it, and a failed balance check leaves the request pending rather
     * than marking it as taken.
     */
    public function approve(Request $request, CashHandover $cashHandover)
    {
        $viewer = $request->user();

        if (! $this->mayDecide($viewer, $cashHandover)) {
            abort(403, 'This hand-in was sent to another super agent.');
        }

        $validated = $request->validate([
            'decision_note' => 'nullable|string|max:255',
        ]);

        try {
            $moved = $cashHandover->approve($validated['decision_note'] ?? null);
        } catch (InsufficientFundsException $e) {
            return back()->withErrors(['handover' => $e->getMessage().' The request is still pending.']);
        }

        if (! $moved) {
            return back()->withErrors(['handover' => 'That hand-in has already been decided.']);
        }

        ActivityLog::create([
            'user_id' => $viewer->id,
            ...ActivityLog::subjectAttributes($cashHandover),
            'action' => 'cash_handover_approved',
            'description' => $viewer->name.' received '.$cashHandover->figure().' from '
                .$cashHandover->agent->name.' ('.$cashHandover->reference.').',
            'new_value' => json_encode([
                'reference' => $cashHandover->reference,
                'amount' => $cashHandover->figure(),
                'agent' => $cashHandover->agent->name,
                'super_agent' => $viewer->name,
            ]),
        ]);

        $bookings = $cashHandover->transactions()->count();
        $charges = bccomp((string) $cashHandover->charge_ugx, '0.00', 2) > 0
            ? ' The '.$cashHandover->chargeFigure().' those transfers charged is yours to keep; only the money the'
                .' transfers are worth goes on to China.'
            : '';

        return back()->with('success', $cashHandover->figure().' received from '.$cashHandover->agent->name.'.'
            .($bookings > 0 ? ' '.$bookings.' transfer'.($bookings === 1 ? '' : 's').' now read Ready to send to China.' : '')
            .$charges);
    }

    /**
     * The cash never arrived, or the note does not match it. The reason is required because the
     * agent sees it beside the declined request and has to know what to do next.
     */
    public function decline(Request $request, CashHandover $cashHandover)
    {
        $viewer = $request->user();

        if (! $this->mayDecide($viewer, $cashHandover)) {
            abort(403, 'This hand-in was sent to another super agent.');
        }

        $validated = $request->validate([
            'decision_note' => 'required|string|max:255',
        ], [
            'decision_note.required' => 'Say why you are sending this hand-in back.',
        ]);

        // Counted before the decision, because sending a request back releases the bookings it named
        // and after that there is nothing left on this row to count.
        $bookings = $cashHandover->transactions()->count();

        if (! $cashHandover->decline($validated['decision_note'])) {
            return back()->withErrors(['handover' => 'That hand-in has already been decided.']);
        }

        ActivityLog::create([
            'user_id' => $viewer->id,
            ...ActivityLog::subjectAttributes($cashHandover),
            'action' => 'cash_handover_declined',
            'description' => $viewer->name.' declined '.$cashHandover->figure().' from '
                .$cashHandover->agent->name.' ('.$cashHandover->reference.'): '.$validated['decision_note'],
            'new_value' => json_encode([
                'reference' => $cashHandover->reference,
                'amount' => $cashHandover->figure(),
                'agent' => $cashHandover->agent->name,
                'reason' => $validated['decision_note'],
            ]),
        ]);

        $released = $bookings > 0
            ? $bookings.' transfer'.($bookings === 1 ? '' : 's').' read Cash taken again'
            : 'nothing was promised yet';

        return back()->with('success', 'Hand-in '.$cashHandover->reference.' declined. The money stays in '
            .$cashHandover->agent->name."'s wallet, and ".$released.'.');
    }
}

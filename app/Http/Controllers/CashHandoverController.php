<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\CashHandover;
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
    ];

    /** The labels of the same states, because "Sent back" is what she says out loud. */
    public const STATUS_LABELS = [
        CashHandover::PENDING => 'Pending',
        CashHandover::APPROVED => 'Approved',
        CashHandover::DECLINED => 'Declined',
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
        // dollars and yuan are not one total.
        $totals = $handovers->reduce(function (array $carry, CashHandover $handover) {
            $code = strtoupper($handover->currency);
            $carry[$code] ??= ['count' => 0, 'amount' => '0'];
            $carry[$code]['count']++;
            $carry[$code]['amount'] = bcadd($carry[$code]['amount'], (string) $handover->amount, 2);

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
        $query = CashHandover::query()->with(['agent', 'superAgent']);

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
            'Status', 'Their note', 'Decision note', 'Decided at'];

        foreach ($headers as $index => $header) {
            $sheet->setCellValue(chr(ord('A') + $index).'1', $header);
        }

        $sheet->getStyle('A1:K1')->applyFromArray([
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
            $this->putText($sheet, 'H'.$row, (string) (self::STATUS_LABELS[$handover->status] ?? ucfirst((string) $handover->status)));
            $this->putText($sheet, 'I'.$row, (string) ($handover->note ?? ''));
            $this->putText($sheet, 'J'.$row, (string) ($handover->decision_note ?? ''));
            $this->putText($sheet, 'K'.$row, $handover->decided_at?->format('Y-m-d h:i A') ?? '');
            $row++;
        }

        if ($row > 2) {
            $sheet->getStyle('F2:F'.($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('A2:K'.($row - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
        }

        foreach (range('A', 'K') as $column) {
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

            if ($handover->super_agent_id !== $viewer->id) {
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

    public function store(Request $request)
    {
        $viewer = $request->user();

        // Cash travels the other way in China: the desk there pays receivers out of what was sent
        // to it, so it has nothing to hand up to a super agent in Uganda.
        if (! $viewer->hasRole('agent') || $viewer->hasRole('super-agent')
            || ($viewer->country ?: User::DEFAULT_COUNTRY) === 'CN') {
            return back()->withErrors(['super_agent_id' => 'Only an agent in Uganda hands cash in to a super agent.']);
        }

        $validated = $request->validate([
            'super_agent_id' => 'required|integer',
            'currency' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ], [
            'super_agent_id.required' => 'Choose the super agent you are handing the cash to.',
            'currency.required' => 'Choose which wallet the cash is coming from.',
            'amount.required' => 'Enter the amount you are handing in.',
            'amount.min' => 'Enter an amount greater than zero.',
        ]);

        $superAgent = User::find($validated['super_agent_id']);

        if (! $superAgent || ! $superAgent->hasRole('super-agent') || ! $superAgent->is_active) {
            return back()->withErrors(['super_agent_id' => 'Choose an active super agent.'])->withInput();
        }

        $currency = strtoupper($validated['currency']);

        if (! in_array($currency, Account::currenciesFor($viewer), true)) {
            return back()->withErrors(['currency' => "Your desk does not hold {$currency}."])->withInput();
        }

        if (! in_array($currency, Account::currenciesFor($superAgent), true)) {
            return back()->withErrors([
                'super_agent_id' => $superAgent->name.' does not hold a '.$currency.' wallet.',
            ])->withInput();
        }

        $wallet = Account::walletFor($viewer, $currency);
        $balance = $wallet->balance();

        // The request is typed against the drawer in front of them, so the most anybody can ask
        // to hand in is what the wallet actually holds at that moment.
        if (bccomp((string) $validated['amount'], (string) $balance, 2) > 0) {
            return back()->withErrors([
                'amount' => 'You cannot hand in more than the wallet holds. The '.$currency.' wallet holds '
                    .Account::format($currency, $balance).'.',
            ])->withInput();
        }

        // One hand-in at a time per agent. The agent's own row is locked while the request is
        // written, so two tabs sending at once cannot both find the drawer without an open
        // hand-in. The request that loses the race is handed back rather than written twice.
        $handover = DB::transaction(function () use ($viewer, $superAgent, $currency, $validated, $request) {
            User::whereKey($viewer->id)->lockForUpdate()->first();

            $open = CashHandover::openFor($viewer)->latest('id')->first();

            if ($open) {
                return $open;
            }

            return CashHandover::request(
                $viewer,
                $superAgent,
                $currency,
                (string) $validated['amount'],
                $request->input('note') ?: null,
            );
        });

        if (! $handover->wasRecentlyCreated) {
            return back()->withErrors([
                'handover' => 'You already have '.$handover->figure().' waiting for '
                    .($handover->superAgent?->name ?: 'your super agent').' to approve or send it back ('
                    .$handover->reference.'). You can send another hand-in once that request is closed.',
            ])->withInput();
        }

        ActivityLog::create([
            'user_id' => $viewer->id,
            ...ActivityLog::subjectAttributes($handover),
            'action' => 'cash_handover_requested',
            'description' => $viewer->name.' asked '.$superAgent->name.' to take '.$handover->figure()
                .' ('.$handover->reference.').',
            'new_value' => json_encode([
                'reference' => $handover->reference,
                'amount' => $handover->figure(),
                'currency' => $currency,
                'agent' => $viewer->name,
                'super_agent' => $superAgent->name,
                'note' => $handover->note,
            ]),
        ]);

        return back()->with('success', 'Hand-in of '.$handover->figure().' sent to '.$superAgent->name
            .' for approval. Reference '.$handover->reference.'.');
    }

    /**
     * The super agent confirms the cash is now in their hand. Only the person the request was
     * addressed to may decide it, and a failed balance check leaves the request pending rather
     * than marking it as taken.
     */
    public function approve(Request $request, CashHandover $cashHandover)
    {
        $viewer = $request->user();

        if ($cashHandover->super_agent_id !== $viewer->id) {
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

        return back()->with('success', $cashHandover->figure().' received from '.$cashHandover->agent->name.'.');
    }

    /**
     * The cash never arrived, or the note does not match it. The reason is required because the
     * agent sees it beside the declined request and has to know what to do next.
     */
    public function decline(Request $request, CashHandover $cashHandover)
    {
        $viewer = $request->user();

        if ($cashHandover->super_agent_id !== $viewer->id) {
            abort(403, 'This hand-in was sent to another super agent.');
        }

        $validated = $request->validate([
            'decision_note' => 'required|string|max:255',
        ], [
            'decision_note.required' => 'Say why you are sending this hand-in back.',
        ]);

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

        return back()->with('success', 'Hand-in '.$cashHandover->reference.' declined. The money stays in '
            .$cashHandover->agent->name."'s wallet.");
    }
}

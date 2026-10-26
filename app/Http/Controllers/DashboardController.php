<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\CashHandover;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\LoginLog;
use App\Models\SystemLog;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        // The badge is read by people, so it carries the same display name the sidebar and the
        // Users table use instead of the raw role slug.
        $slug = (string) $user->getRoleNames()->first();
        $role = RolePermissionController::displayName($slug !== '' ? $slug : 'user');
        // The greeting gets the same treatment: a desk is greeted by its whole name, and an account
        // named after nothing but its own role is greeted by that role's display name.
        $greetAs = $this->greetAs($user, $slug);
        $usdToUgx = $this->usdToUgxRate();

        // The board prints every figure once, out of the window the reader has picked. The
        // all-time and today windows are also what the Reports cross-check reads, so the flat
        // stats below are named from them rather than summed a second time.
        $windows = $this->metricWindows($user, $usdToUgx);
        $all = $windows['all'];
        $today = $windows['today'];

        $stats = [
            'total_transactions' => Transaction::query()->visibleTo($user)->count(),
            'total_usd_sent' => $all['sent_usd'],
            'total_revenue' => $all['charge_usd'],
            'total_rmb_sent' => $all['rmb'],
            'total_ugx_sent' => $all['sent_ugx'],
            'total_revenue_ugx' => $all['charge_ugx'],
            'pending_transactions' => $all['pending'],
            'completed_transactions' => $all['completed'],
            'cancelled_transactions' => $all['cancelled'],
            'avg_transaction' => $all['avg_usd_sent'],
            'today_transactions' => $today['completed'],
            'today_usd_sent' => $today['sent_usd'],
            'today_revenue' => $today['charge_usd'],
            'today_ugx_sent' => $today['sent_ugx'],
            'today_revenue_ugx' => $today['charge_ugx'],
            'avg_ugx_sent' => $all['avg_ugx_sent'],
        ];

        $stats['total_users'] = $user->can('view-users') ? User::where('is_active', true)->count() : 0;
        $stats['total_agents'] = $user->can('view-users') ? User::role('agent')->where('is_active', true)->count() : 0;
        $stats['active_rates'] = $user->can('view-exchange-rates') ? ExchangeRate::where('is_active', true)->count() : 0;

        $recentTransactions = Transaction::query()
            ->visibleTo($user)
            ->latest()
            ->take(10)
            ->get();

        $chartData = $this->buildChartData($user, $usdToUgx);

        // One chart per role. Whoever owns the book gets the charge and the exchange figures
        // stacked into the overall profit; everyone else only sees the charge they earned. Both
        // series are always in shillings, which is where the money is kept.
        $seesProfit = $user->can('view-profit');

        if (! $seesProfit) {
            unset($chartData['exchange_ugx']);
        }

        $profit = $seesProfit ? $this->profitWindows($user, $usdToUgx) : [];

        // The pair of cards is custody: money this person is holding and owes a hand-in for.
        // Anyone who reads every wallet (Super Admin, Admin, Accountant) does that on the Wallets
        // and Accounts screens, so the board gives them no personal pair. It also stops the visit
        // minting two empty accounts for a desk that never holds money, because the wallets are
        // created on demand.
        $holdsMoney = ! $user->can('view-all-wallets');

        $wallets = $holdsMoney ? Account::walletsFor($user) : [];
        $handedIn = AccountEntry::handedInFor($wallets);

        // The hand-in form opens on this page for an ordinary agent, so the lead agents it can
        // address are read here as well as on the wallet screen. Both use the same rule: only an
        // active super agent can receive the request.
        $superAgents = $user->hasRole('agent') && ! $user->hasRole('super-agent') && $user->can('view-wallets')
            ? User::role('super-agent')->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : collect();

        // The super agent's queue is the cash the agents say they are bringing. It is shown here
        // rather than only on the wallet page because this is the screen the super agent opens
        // first, and the money should not wait on somebody remembering to look elsewhere. What was
        // decided lately stays beside it so the card still answers "did that go through?".
        $handoverQueue = collect();
        $handoverHistory = collect();

        if ($user->hasRole('super-agent')) {
            $handoverQueue = CashHandover::with('agent:id,name')
                ->pendingFor($user)
                ->oldest('id')
                ->get();

            $handoverHistory = CashHandover::with('agent:id,name')
                ->where('super_agent_id', $user->id)
                ->where('status', '!=', CashHandover::PENDING)
                ->latest('decided_at')
                ->limit(5)
                ->get();
        }

        // An ordinary agent sees what they are still waiting on, so a request that has not been
        // decided is not mistaken for cash already handed over. It is read for anybody holding a
        // wallet rather than only for the agent role, because the figure is a slice of the balance
        // on the card and the card is handed to every desk that holds money.
        $pendingHandIn = $holdsMoney ? $this->pendingHandInByCurrency($user) : [];

        // The same insight for the whole hand-in book: what this person asked, in how many pieces,
        // and the last one they sent. Read from their own rows here rather than from anything the
        // address bar carries, so one desk's board cannot be pointed at another desk's cash.
        $handoverInsight = $user->hasRole('agent') ? $this->handoverInsight($user) : null;

        // The other half of the corridor: dollars that came from Uganda have reached a desk in
        // China and the receiver is still to be handed the money. This is the queue the Withdraw
        // shortcut opens, counted on the same rule the China desk lists it by so the shortcut and
        // the page it opens never disagree: the payout pen sees the whole waiting list, wherever
        // its desk stands.
        $withdrawalsWaiting = 0;

        if ($user->can('view-transactions') && $user->can('confirm-china-payout')) {
            $withdrawalsWaiting = Transaction::awaitingPayoutInChina()->count();
        }

        // The mirror at the other end: a booking made in China is waiting to be paid out in
        // Uganda from the day it is taken, and this is the queue the Uganda desk hands over.
        $ugandaWithdrawalsWaiting = 0;

        if ($user->can('view-transactions') && $user->can('confirm-china-payout')) {
            $ugandaWithdrawalsWaiting = Transaction::awaitingPayoutInUganda()->count();
        }

        // What the charge pen has kept this month, kept apart by the wallet it landed in so each
        // card prints its own figure and a card with nothing to report prints a zero rather than
        // dropping its row. Commission is only ever typed into a shilling wallet, so the dollar
        // and yuan cards honestly read nil; the old query reached for a shilling account a China
        // desk is never shown, which printed nothing anywhere and opened the account on sight.
        $feesMonth = $this->feesThisMonth($wallets);
        $feesPeriod = now()->startOfMonth()->format('j M') . ' to ' . now()->format('j M Y');

        // Admins manage people, so a spike of failed sign-ins belongs on the page they open
        // first; only the Super Admin sees the machine errors, matching the Logs tabs.
        $logAlerts = [];
        if ($user->can('view-login-logs')) {
            try {
                $logAlerts['failed_logins_24h'] = LoginLog::where('status', 'failed')
                    ->where('created_at', '>=', now()->subDay())
                    ->count();
                if ($user->can('view-system-logs')) {
                    $logAlerts['unresolved_errors'] = SystemLog::where('resolved', false)->count();
                }
            } catch (\Throwable) {
                // login_logs / system_logs not migrated yet; show no badges.
            }
        }

        // The spending book is a different reach from the transfer book: a role that may not open
        // it gets no card at all rather than a figure it cannot check.
        $spending = $user->can('view-expenses') ? $this->spendingToday($user) : ['count' => 0, 'desks' => []];

        // The desks the agents answer to get one extra Overview card: the cash those agents
        // collected themselves, principal plus charge, so the whole book's Sent figure can be
        // told apart from what the agents' hands actually took in.
        $agentTaken = $user->hasAnyRole(['superadmin', 'admin', 'super-agent'])
            ? $this->agentTakenWindows($usdToUgx)
            : [];

        return view('dashboard', compact('stats', 'recentTransactions', 'chartData', 'role', 'usdToUgx', 'wallets', 'handedIn', 'feesMonth', 'feesPeriod', 'profit', 'windows', 'logAlerts', 'spending', 'handoverQueue', 'handoverHistory', 'pendingHandIn', 'handoverInsight', 'superAgents', 'withdrawalsWaiting', 'ugandaWithdrawalsWaiting', 'agentTaken', 'greetAs'));
    }

    /**
     * The name the board greets this person by.
     *
     * The whole account name is used, never its first word: several desks share one role, so a
     * first word that happens to be the role cannot say whose hand the balances below are in, and
     * a truncated one reads like a placeholder somebody forgot to fill in. Where the name says
     * nothing beyond the role, the role's own display name is printed instead of whatever casing
     * was typed into the name box. That is the general case rather than one string, so the shared
     * office sign-in ("Super Admin"), a demo desk ("Agent Ug.") and an account simply called
     * "agent" are all handled the same way.
     */
    private function greetAs(User $user, string $slug): string
    {
        $name = trim($user->name);

        if ($name === '') {
            return RolePermissionController::displayName($slug !== '' ? $slug : 'user');
        }

        if ($slug === '') {
            return $name;
        }

        // Only the letters matter, so "Super Admin", "superadmin" and "super-admin" all say the
        // same thing. A desk code beside the role (UG, CN) is the same statement with a location
        // pinned on, so it is dropped before the comparison.
        $typed = preg_replace('/[^a-z]/i', '', str_replace('-', ' ', $name));
        $bare = preg_replace('/\b(ug|cn)\b/i', '', $name);

        foreach ([str_replace('-', ' ', $slug), RolePermissionController::displayName($slug)] as $role) {
            if (strcasecmp(preg_replace('/[^a-z]/i', '', $role), $typed) === 0
                || strcasecmp(preg_replace('/[^a-z]/i', '', $role), $bare) === 0) {
                return RolePermissionController::displayName($slug);
            }
        }

        return $name;
    }

    /**
     * Cash this person has asked to hand over that is still waiting to be approved, apart by
     * wallet. A request moves no money until the lead agent approves it, so every figure here is
     * already inside the balance on the card above it. It is a slice, never money on top.
     *
     * @return array<string, string>
     */
    private function pendingHandInByCurrency(User $user): array
    {
        return CashHandover::where('agent_id', $user->id)
            ->where('status', CashHandover::PENDING)
            ->selectRaw('currency, COALESCE(SUM(amount), 0) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->all();
    }

    /**
     * The hand-in book read small: how many requests this person has sent in each state, what
     * they come to in each wallet, and the last one they sent with the time since. Their own rows
     * only, taken from the signed-in account rather than from the request.
     *
     * @return array{statuses: array<string, array{count: int, totals: array<string, string>}>, latest: ?CashHandover, sent: int}
     */
    private function handoverInsight(User $user): array
    {
        $rows = CashHandover::where('agent_id', $user->id)
            ->selectRaw('status, currency, COUNT(*) as requests, COALESCE(SUM(amount), 0) as total')
            ->groupBy('status', 'currency')
            ->get();

        // The three states are always in this order, and a state with nothing in it stays on the
        // card at zero so the reader is not left wondering which one went missing.
        $statuses = [
            CashHandover::PENDING => ['count' => 0, 'totals' => []],
            CashHandover::APPROVED => ['count' => 0, 'totals' => []],
            CashHandover::DECLINED => ['count' => 0, 'totals' => []],
        ];

        foreach ($rows as $row) {
            if (! isset($statuses[$row->status])) {
                $statuses[$row->status] = ['count' => 0, 'totals' => []];
            }

            $statuses[$row->status]['count'] += (int) $row->requests;
            $statuses[$row->status]['totals'][$row->currency] = (string) $row->total;
        }

        // Approval moves the cash out of the drawer balance, so the money the agent is still
        // answerable for reads the approved pile back in: principal per currency plus the charges,
        // which always crossed in shillings. Pending requests never moved, so they are already in
        // the balance and stay out of this figure.
        $approved = $statuses[CashHandover::APPROVED]['totals'];
        $approvedCharges = (string) CashHandover::where('agent_id', $user->id)
            ->where('status', CashHandover::APPROVED)
            ->sum('charge_ugx');

        if (bccomp($approvedCharges, '0', 2) > 0) {
            $approved['UGX'] = bcadd((string) ($approved['UGX'] ?? '0.00'), $approvedCharges, 2);
        }

        return [
            'statuses' => $statuses,
            'latest' => CashHandover::where('agent_id', $user->id)->latest('id')->first(),
            'sent' => array_sum(array_column($statuses, 'count')),
            'approved' => $approved,
        ];
    }

    /**
     * What the charge pen kept from the first of the month to today, in each of the wallets this
     * board prints. Reversals come back off, so a cancelled booking does not keep counting as
     * earnings in a figure a person reads as theirs.
     *
     * @param  array<string, Account>  $wallets
     * @return array<string, float>
     */
    private function feesThisMonth(array $wallets): array
    {
        if ($wallets === []) {
            return [];
        }

        $net = AccountEntry::query()
            ->whereIn('account_id', collect($wallets)->pluck('id')->all())
            ->whereIn('type', ['agent_fee', 'agent_fee_reversal'])
            ->whereDate('entry_date', '>=', now()->startOfMonth()->toDateString())
            ->whereDate('entry_date', '<=', now()->toDateString())
            ->selectRaw('account_id, COALESCE(SUM(CASE WHEN type = \'agent_fee\' THEN amount ELSE -amount END), 0) as total')
            ->groupBy('account_id')
            ->pluck('total', 'account_id');

        return collect($wallets)
            ->mapWithKeys(fn (Account $account, string $currency) => [$currency => (float) ($net[$account->id] ?? 0)])
            ->all();
    }

    /**
     * What was spent today, kept apart by the desk that paid it out. The transfers above the fold
     * are money coming in; this is the money going out on the same day, and until now the only way
     * to see it was to open the spending book.
     *
     * Today means recorded today rather than dated today, because a fare paid on Friday and typed up
     * on Monday was made today. The country is read off the account that paid, which is the same rule
     * the spending book filters by, so the two screens cannot split the same entry two ways.
     *
     * @return array{count: int, desks: array<int, array<string, mixed>>}
     */
    private function spendingToday(User $user): array
    {
        $rows = Expense::with(['account:id,country,currency', 'creator:id,name'])
            ->where('status', Expense::ACTIVE)
            ->whereDate('created_at', today())
            // A desk without the whole book sees its own spending, which is the same reach the
            // spending page gives it.
            ->when(! $user->can('view-all-expenses'), fn (Builder $q) => $q->where('created_by', $user->id))
            ->get();

        $ownCountry = $user->country ?: User::DEFAULT_COUNTRY;
        $corridor = array_flip(array_keys(User::COUNTRIES));

        $desks = $rows->groupBy(fn (Expense $row) => $row->account?->country ?: User::DEFAULT_COUNTRY)
            // Their own desk's money first, the other country after it, in the order the two
            // countries are declared rather than alphabetically.
            ->sortBy(fn ($group, string $country) => [$country === $ownCountry ? 0 : 1, $corridor[$country] ?? count($corridor)])
            ->map(fn ($group, string $country) => [
                'label' => User::COUNTRIES[$country] ?? $country,
                'count' => $group->count(),
                'people' => $group->pluck('creator.name')->filter()->unique()->sort()->values()->all(),
                'totals' => $group->groupBy('currency')
                    ->map(fn ($byCurrency) => (string) $byCurrency->sum('amount'))
                    ->all(),
            ])
            ->values()
            ->all();

        return ['count' => $rows->count(), 'desks' => $desks];
    }

    private function usdToUgxRate(): ?float
    {
        return ExchangeRate::usdToUgx();
    }

    /**
     * UGX as each transfer was actually priced, the same way Reports reads it.
     *
     * Converting the stored dollars at today's rate is what made the dashboard drift from
     * Reports whenever the rate moved. exchange_rate is the dollar to RMB rate, so the UGX
     * price a dollar-funded transfer was sold at lives in rate_sell_ugx; rows booked before
     * that column was filled fall back to today's selling rate.
     *
     * @return array{sent: string, fee: string}
     */
    private function ugxTotals(?float $usdToUgx): array
    {
        $today = number_format((float) ($usdToUgx ?? 0), 4, '.', '');

        $principal = "(CASE WHEN currency = 'UGX' THEN amount"
            . " ELSE total_usd_equivalent * COALESCE(NULLIF(rate_sell_ugx, 0), {$today}) END)";
        $fee = "(CASE WHEN charge_ugx > 0 THEN charge_ugx"
            . " ELSE charge_amount * COALESCE(NULLIF(rate_sell_ugx, 0), {$today}) END)";

        return [
            'sent' => "COALESCE(SUM(CASE WHEN status = 'completed' THEN {$principal} ELSE 0 END), 0)",
            'fee' => "COALESCE(SUM(CASE WHEN status = 'completed' THEN {$fee} ELSE 0 END), 0)",
            'pending' => "COALESCE(SUM(CASE WHEN status = 'pending' THEN {$principal} ELSE 0 END), 0)",
        ];
    }

    private function buildChartData(User $user, ?float $usdToUgx): array
    {
        $startDate = now()->subDays(6)->startOfDay();
        $ugxRevenue = $this->ugxTotals($usdToUgx)['fee'];
        $exchange = $this->exchangeProfitSql();

        $daily = Transaction::query()
            ->visibleTo($user)
            ->where('created_at', '>=', $startDate)
            ->selectRaw("DATE(created_at) as date, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as transactions, COALESCE(SUM(CASE WHEN status='completed' THEN charge_amount ELSE 0 END), 0) as revenue, {$ugxRevenue} as revenue_ugx, COALESCE(SUM(CASE WHEN status='completed' THEN {$exchange} ELSE 0 END), 0) as exchange_ugx")
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get()
            ->keyBy('date');

        $labels = [];
        $transactions = [];
        $revenue = [];
        $revenueUgx = [];
        $exchangeUgx = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $labels[] = now()->subDays($i)->format('M d');
            $transactions[] = (int) ($daily[$date]->transactions ?? 0);
            $revenue[] = (float) ($daily[$date]->revenue ?? 0);
            $revenueUgx[] = (float) ($daily[$date]->revenue_ugx ?? 0);
            $exchangeUgx[] = (float) ($daily[$date]->exchange_ugx ?? 0);
        }

        return [
            'labels' => $labels,
            'transactions' => $transactions,
            'revenue' => $revenue,
            'revenue_ugx' => $revenueUgx,
            'exchange_ugx' => $exchangeUgx,
        ];
    }

    /**
     * The counts and the money for each stretch of time the board can be read over, so the
     * same figures are not printed in three separate blocks that each need their own label.
     *
     * This is the only money query on the page: the flat stats the Reports cross-check reads
     * are named out of the all-time and today windows rather than summed again. Pending
     * transfers are counted and summed so the To Be Received card can show money still to
     * send, and its stamp is the newest pending booking; cancelled ones only feed the status
     * line and stay out of every money figure.
     *
     * @return array<string, array<string, float|int|\Illuminate\Support\Carbon|null>>
     */
    private function metricWindows(User $user, ?float $usdToUgx): array
    {
        ['sent' => $ugxSent, 'fee' => $ugxRevenue, 'pending' => $ugxPending] = $this->ugxTotals($usdToUgx);

        $windows = [
            'today' => fn (Builder $q) => $q->whereDate('created_at', today()),
            'month' => fn (Builder $q) => $q->whereBetween('created_at', [now()->startOfMonth(), now()]),
            'all' => fn (Builder $q) => $q,
        ];

        $sums = [];

        foreach ($windows as $window => $restrict) {
            $row = (array) $restrict(Transaction::query()->visibleTo($user))
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) as completed,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending,
                    COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) as cancelled,
                    {$ugxSent} as sent_ugx,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN total_usd_equivalent ELSE 0 END), 0) as sent_usd,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN rmb_amount ELSE 0 END), 0) as rmb,
                    {$ugxRevenue} as charge_ugx,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN charge_amount ELSE 0 END), 0) as charge_usd,
                    {$ugxPending} as pending_ugx,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN total_usd_equivalent ELSE 0 END), 0) as pending_usd,
                    MAX(CASE WHEN status = 'pending' THEN created_at ELSE NULL END) as latest_pending_at
                ")
                // The yuan each booking promised splits three ways by how far it has actually
                // travelled, and the six figures come off the same rows as the totals above.
                ->selectRaw(implode(', ', Transaction::yuanPileSql()))
                ->selectRaw(implode(', ', Transaction::yuanPileCountSql()))
                ->toBase()
                ->first();

            $completed = (int) $row['completed'];

            $sums[$window] = [
                'completed' => $completed,
                'pending' => (int) $row['pending'],
                'cancelled' => (int) $row['cancelled'],
                'sent_ugx' => (float) $row['sent_ugx'],
                'sent_usd' => (float) $row['sent_usd'],
                'rmb' => (float) $row['rmb'],
                'charge_ugx' => (float) $row['charge_ugx'],
                'charge_usd' => (float) $row['charge_usd'],
                'pending_ugx' => (float) $row['pending_ugx'],
                'pending_usd' => (float) $row['pending_usd'],
                'latest_at' => $row['latest_pending_at'] ? \Illuminate\Support\Carbon::parse($row['latest_pending_at']) : null,
                'yuan_in_uganda' => (float) $row['rmb_in_uganda'],
                'yuan_in_china' => (float) $row['rmb_in_china'],
                'yuan_paid' => (float) $row['rmb_paid'],
                'yuan_in_uganda_transfers' => (int) $row['transfers_in_uganda'],
                'yuan_in_china_transfers' => (int) $row['transfers_in_china'],
                'yuan_paid_transfers' => (int) $row['transfers_paid'],
                // Derived here from the shillings already summed, so the average cannot disagree
                // with the Sent figure the way an average of re-converted dollars did.
                'avg_ugx_sent' => $completed > 0 ? (float) $row['sent_ugx'] / $completed : 0.0,
                'avg_usd_sent' => $completed > 0 ? (float) $row['sent_usd'] / $completed : 0.0,
            ];
        }

        return $sums;
    }

    /**
     * The cash the agents' own hands collected, per window: completed bookings the agent or the
     * super agent keyed in, principal plus the typed charge, in shillings. It is the slice of
     * the window's Sent and Charge cards that belongs to agent bookings rather than counter
     * ones, so the figure never disagrees with them: it reuses their own SQL expressions.
     *
     * @return array<string, float>
     */
    private function agentTakenWindows(?float $usdToUgx): array
    {
        ['sent' => $ugxSent, 'fee' => $ugxRevenue] = $this->ugxTotals($usdToUgx);

        $agentIds = User::role(['agent', 'super-agent'])->pluck('id');

        if ($agentIds->isEmpty()) {
            return ['today' => 0.0, 'month' => 0.0, 'all' => 0.0];
        }

        $windows = [
            'today' => fn (Builder $q) => $q->whereDate('created_at', today()),
            'month' => fn (Builder $q) => $q->whereBetween('created_at', [now()->startOfMonth(), now()]),
            'all' => fn (Builder $q) => $q,
        ];

        $taken = [];

        foreach ($windows as $window => $restrict) {
            $row = (array) $restrict(Transaction::query()->whereIn('created_by', $agentIds))
                ->selectRaw("{$ugxSent} as sent_ugx, {$ugxRevenue} as charge_ugx")
                ->toBase()
                ->first();

            $taken[$window] = (float) $row['sent_ugx'] + (float) $row['charge_ugx'];
        }

        return $taken;
    }

    /**
     * What the three profit figures come to over a stretch of time, in shillings as each
     * transfer was priced rather than re-converted at today's rate, so the dashboard cannot
     * drift from Reports when the rate moves.
     *
     * The yuan leg is missing on transfers booked before the rates were snapshotted, so those
     * are counted and reported as unpriced instead of being quietly added as zero.
     *
     * @return array<string, array{fees: float, exchange: float, total: float, unpriced: int}>
     */
    private function profitWindows(User $user, ?float $usdToUgx): array
    {
        $fee = $this->ugxTotals($usdToUgx)['fee'];
        $exchange = $this->exchangeProfitSql();

        $windows = [
            'today' => fn (Builder $q) => $q->whereDate('created_at', today()),
            'month' => fn (Builder $q) => $q->whereBetween('created_at', [now()->startOfMonth(), now()]),
            'all' => fn (Builder $q) => $q,
        ];

        $sums = [];

        foreach ($windows as $window => $restrict) {
            // ugxTotals() hands back a finished aggregate, while the exchange expression is
            // per row and has to be summed here.
            $row = (array) $restrict(Transaction::query()->visibleTo($user))
                ->selectRaw("{$fee} as fees")
                ->selectRaw("COALESCE(SUM(CASE WHEN status = 'completed' THEN {$exchange} ELSE 0 END), 0) as exchange")
                ->selectRaw("SUM(CASE WHEN status = 'completed' AND (COALESCE(rate_buy_ugx, 0) <= 0 OR usd_rmb_profit_cny IS NULL) THEN 1 ELSE 0 END) as unpriced")
                ->toBase()
                ->first();

            $sums[$window] = [
                'fees' => (float) ($row['fees'] ?? 0),
                'exchange' => (float) ($row['exchange'] ?? 0),
                'total' => (float) ($row['fees'] ?? 0) + (float) ($row['exchange'] ?? 0),
                'unpriced' => (int) ($row['unpriced'] ?? 0),
            ];
        }

        return $sums;
    }

    /**
     * Both currency legs together: the spread taken on the shillings and the spread paid or
     * earned turning the dollars into yuan. A transfer that was never priced contributes
     * nothing here, which is why the unpriced count travels alongside it.
     */
    private function exchangeProfitSql(): string
    {
        return "COALESCE(fx_profit_ugx, 0) + COALESCE(usd_rmb_profit_ugx, 0)";
    }
}

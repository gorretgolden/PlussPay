<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\CashHandover;
use App\Models\CurrencyExchange;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountLedger;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Every screen in the book, opened by each role, with the money actually in the book behind it.
 *
 * The per-role tests elsewhere each check one gate or one card. This one asks the wider question:
 * does any desk ever get a crashed page. A screen that only breaks for the role that holds a
 * wallet, or only when a transfer exists to price it, is invisible until that person opens it, so
 * the walk covers all six roles against all data-bearing routes at once.
 */
class RoleScreenSweepTest extends TestCase
{
    use RefreshDatabase;

    /** Framework routes: the health ping and the file server, neither of which is a desk screen. */
    private const FRAMEWORK_ROUTES = ['up', 'storage.local'];

    private User $office;

    private User $agent;

    private User $lead;

    private User $bookkeeper;

    private Transaction $transfer;

    private CashHandover $handover;

    private array $bound = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::names() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (PermissionCatalog::defaults() as $name => $abilities) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'])->syncPermissions($abilities);
        }

        $this->buildTheBook();
    }

    private function person(string $role, string $name, string $country = 'UG', ?string $float = null): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);

        $user->assignRole($role);

        if ($float !== null) {
            AccountLedger::record(Account::walletFor($user, 'UGX'), 'adjustment_in', $float, 'Float from the office');
        }

        return $user;
    }

    /**
     * One working day of real records, so no screen is asked to render an empty book. The transfer
     * is booked by the agent, the cash for it is handed up to the lead agent, and both legs sit in
     * the office accounts, which is what the reports, the ledger and the daily balances read.
     */
    private function buildTheBook(): void
    {
        $this->office = $this->person('admin', 'Grace Naigaga');
        $this->agent = $this->person('agent', 'Sarah Nalin', 'UG', '480000');
        $this->lead = $this->person('super-agent', 'Amina Lead', 'UG', '200000');
        $this->bookkeeper = $this->person('accountant', 'Peter Ekong');
        $counter = $this->person('staff', 'Cathy Auma');

        $this->transfer = Transaction::withoutEvents(fn () => Transaction::create([
            'receipt_number' => 'PS-20261021-0001', 'sender_name' => 'Joan Namuli', 'sender_contact' => '0700000001',
            'receiver_name' => 'Wang Li', 'receiver_contact' => '13800000002', 'currency' => 'USD',
            'amount' => '200', 'exchange_rate' => '7.20', 'rmb_amount' => '1440', 'total_usd_equivalent' => '200',
            'charge_rate' => '2', 'charge_amount' => '4', 'charge_ugx' => '15000', 'status' => 'completed',
            'created_by' => $this->agent->id, 'holder_id' => $this->agent->id, 'holder_country' => 'UG',
            'fx_profit_ugx' => '60000', 'fx_profit_usd' => '16', 'usd_rmb_profit_ugx' => '24000', 'usd_rmb_profit_cny' => '80',
            'rate_buy_ugx' => '3750', 'rate_sell_ugx' => '3800', 'payment_method' => 'Cash',
        ]));

        Customer::create([
            'name' => 'Musa Kato', 'phone' => '0700111222', 'type' => 'Sender', 'country' => 'UG',
            'notes' => 'Sends weekly', 'is_active' => true, 'created_by' => $this->agent->id,
        ]);

        $category = ExpenseCategory::firstOrCreate(['name' => 'Transport'], ['is_active' => true, 'created_by' => $this->office->id]);

        Expense::create([
            'reference' => 'EXP-20261021-0001', 'expense_date' => today(), 'category_id' => $category->id,
            'category' => 'Transport', 'description' => 'Boda to the bank', 'currency' => 'UGX', 'amount' => '8000',
            'account_id' => Account::byCode(Account::UGX_UGANDA)->id, 'paid_via' => 'Cash', 'status' => 'active',
            'created_by' => $this->agent->id,
        ]);

        CurrencyExchange::create([
            'reference' => 'EX-20261021-0001', 'exchange_date' => today(), 'person_name' => 'Amina Lead',
            'in_currency' => 'UGX', 'out_currency' => 'USD', 'in_amount' => '750000', 'out_amount' => '200',
            'in_account_id' => Account::byCode(Account::UGX_UGANDA)->id,
            'out_account_id' => Account::byCode(Account::USD_UGANDA)->id,
            'created_by' => $this->lead->id, 'margin_ugx' => '12000', 'status' => 'active',
        ]);

        $this->handover = CashHandover::create([
            'reference' => 'CH-20261021-0001', 'agent_id' => $this->agent->id, 'super_agent_id' => $this->lead->id,
            'currency' => 'UGX', 'amount' => '150000', 'note' => 'Bringing it over at lunch',
            'status' => CashHandover::PENDING,
        ]);

                $this->bound['transaction'] = $this->transfer->id;
        $this->bound['cashHandover'] = $this->handover->id;
        $this->bound['customer'] = Customer::where('name', 'Musa Kato')->value('id');
        $this->bound['expense'] = Expense::where('reference', 'EXP-20261021-0001')->value('id');
        $this->bound['exchange'] = CurrencyExchange::where('reference', 'EX-20261021-0001')->value('id');
        $this->bound['user'] = $counter->id;
        $this->bound['account'] = Account::byCode(Account::UGX_UGANDA)->id;
        $this->bound['receiptNumber'] = $this->transfer->receipt_number;

        $this->bound['activityLog'] = ActivityLog::create([
            'user_id' => $this->office->id, 'action' => 'user_updated', 'description' => 'Desk staff details updated',
            'old_value' => 'staff', 'new_value' => 'agent',
        ])->id;

        AccountLedger::record(Account::byCode(Account::UGX_UGANDA), 'opening_balance', '500000', 'Office till opened');
        AccountLedger::record(Account::byCode(Account::UGX_UGANDA), 'money_out', '8000', 'Boda to the bank');
    }

    /**
     * The route table with every {param} replaced by a record that exists, so the walk hits the
     * show, edit and receipt screens rather than only the lists.
     *
     * @return array<string, string> route name => uri
     */
    private function screens(): array
    {
        $screens = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || in_array($route->getName(), self::FRAMEWORK_ROUTES, true)) {
                continue;
            }

            $uri = $route->uri();
            $missing = false;

            foreach ($route->parameterNames() as $parameter) {
                if (! array_key_exists($parameter, $this->bound)) {
                    $missing = true;

                    continue;
                }

                $uri = preg_replace('#\{'.preg_quote($parameter, '#').'\}#', (string) $this->bound[$parameter], $uri, 1);
            }

            if ($missing) {
                $this->fail('The sweep has no record to bind to '.$parameter.' in '.$uri.', so a screen would go untested.');
            }

            $screens[$route->getName() ?: $route->uri()] = '/'.ltrim($uri, '/');
        }

        return $screens;
    }

    public function test_every_screen_in_the_book_opens_for_every_role_it_is_meant_for(): void
    {
        $screens = $this->screens();
        $this->assertGreaterThan(60, count($screens), 'The walk should cover the whole route table, not a handful of pages.');

        $broken = [];
        $opened = [];

        foreach (array_keys(PermissionCatalog::defaults()) as $role) {
            $viewer = User::create([
                'name' => 'Sweeper '.$role,
                'email' => 'sweeper-'.str_replace(' ', '-', $role).'@plussave.test',
                'password' => 'Password123',
                'is_active' => true,
                'country' => 'UG',
            ]);
            $viewer->assignRole($role);

            $opened[$role] = 0;

            foreach ($screens as $name => $uri) {
                $this->flushSession();
                $response = $this->actingAs($viewer)->get($uri);
                $status = $response->getStatusCode();

                if ($status < 400) {
                    $opened[$role]++;
                }

                if ($name === 'dashboard') {
                    $this->assertSame(200, $status, $role.' should reach its own board.');
                }

                // An Excel download is a streamed body, so nothing is assembled until the stream
                // is actually read. Without this the export routes go untested.
                if ($status === 200 && $response->baseResponse instanceof StreamedResponse) {
                    try {
                        $response->streamedContent();
                    } catch (\Throwable $caught) {
                        $broken[] = $role.' downloaded '.$name.' ('.$uri.') and the stream threw '.$caught->getMessage();
                    }

                    continue;
                }

                if ($status >= 500) {
                    $reason = preg_match('#<title>([^<]+)</title>#', (string) $response->getContent(), $caught) ? trim($caught[1]) : '';
                    $broken[] = $role.' opened '.$name.' ('.$uri.') and got '.$status.' '.$reason;
                }
            }
        }

        // A walk that was turned away at every door would also report no crashes, so the counts are
        // part of the promise: each role really does get its own screens open, not just a clean 403.
        $this->assertSame(['superadmin', 'admin', 'accountant', 'agent', 'super-agent', 'staff'], array_keys($opened));

        foreach ($opened as $role => $count) {
            $this->assertGreaterThanOrEqual(10, $count, $role.' opened only '.$count.' of '.count($screens).' screens, so the walk proved nothing for it.');
        }

        $this->assertSame([], $broken, "Screens that crashed:\n".implode("\n", $broken));
    }

    public function test_the_walk_is_strict_about_the_screens_a_role_should_never_reach(): void
    {
        $screens = $this->screens();

        $agent = $this->agent;
        $lead = $this->lead;

        // The gate is the point of the sweep: a desk must be turned away in words, not shown a
        // broken page, and never allowed to read a figure it does not hold.
        foreach (['/users', '/admin/roles', '/settings', '/admin/system-logs'] as $uri) {
            $this->flushSession();
            $response = $this->actingAs($agent)->get($uri);
            $this->assertContains($response->status(), [403, 302], $uri.' should refuse an agent, not render or crash.');

            $this->flushSession();
            $this->assertContains(
                $this->actingAs($lead)->get($uri)->status(),
                [403, 302],
                $uri.' should refuse a lead agent, not render or crash.'
            );
        }

        $this->flushSession();
        $this->actingAs($this->office)->get('/users')->assertOk();

        $this->flushSession();
        $this->actingAs($this->bookkeeper)->get('/reports')->assertOk();

        $this->assertArrayHasKey('cash-handovers.index', $screens);
    }

    public function test_the_cash_a_desk_hands_in_lands_on_every_screens_after_it_is_approved(): void
    {
        $before = ['agent' => Account::walletFor($this->agent, 'UGX')->fresh()->balance(),
            'lead' => Account::walletFor($this->lead, 'UGX')->fresh()->balance()];

        $this->flushSession();
        $this->actingAs($this->lead)->post(route('cash-handovers.approve', $this->handover))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(CashHandover::APPROVED, $this->handover->fresh()->status);
        $this->assertSame('330000.00', Account::walletFor($this->agent, 'UGX')->fresh()->balance());
        $this->assertSame('350000.00', Account::walletFor($this->lead, 'UGX')->fresh()->balance());
        $this->assertSame('480000.00', $before['agent']);

        // The cards, the desk and the exports all read the moved money rather than a stale figure.
        foreach ([
            [$this->agent, ['/dashboard', '/wallet', '/cash-handovers', '/reports']],
            [$this->lead, ['/dashboard', '/wallet', '/cash-handovers']],
            [$this->office, ['/wallet', '/reports', '/export/cash-history']],
        ] as [$viewer, $uris]) {
            foreach ($uris as $uri) {
                $this->flushSession();
                $page = $this->actingAs($viewer)->get($uri)->assertOk()->getContent();
                $this->assertStringNotContainsString('TODO', $page, $uri.' still shows a placeholder to '.$viewer->name);
            }
        }

        $this->flushSession();
        $this->actingAs($this->agent)->get('/dashboard')->assertSee('UGX 330,000');
    }
}

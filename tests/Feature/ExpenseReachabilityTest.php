<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashHandover;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountLedger;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What the money left the desk for was a sub-link inside the Accounts group, so it stayed hidden
 * until someone thought to open that group, and the list of heads an expense falls under had no
 * sidebar entry at all. These tests read the sidebar and the dashboard as each role does, because
 * the answer is which links appear, not what one screen looks like.
 */
class ExpenseReachabilityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $name, string $country = 'UG'): User
    {
        $model = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $model->syncPermissions(PermissionCatalog::defaults()[$role] ?? []);

        $user = User::create([
            'name' => $name,
            'email' => strtolower($role).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function html(User $user, string $path = '/dashboard'): string
    {
        $this->flushSession();

        return $this->actingAs($user)->get($path)->assertOk()->getContent();
    }

    public function test_a_bookkeeper_sees_expenses_without_opening_another_group(): void
    {
        $accountant = $this->user('accountant', 'Grace Naigaga');

        $this->assertTrue($accountant->can('view-expenses'));

        $html = $this->html($accountant);

        // Its own group in the sidebar, opened by the group's own button rather than hidden inside
        // Accounts or Settings. The board no longer duplicates it: a shortcut for a page the menu
        // already carries is a second place to keep.
        $this->assertStringContainsString('onclick="toggleExpensesGroup()"', $html);
        $this->assertSame(1, preg_match_all('#<a href="[^"]+/expenses" class="sidebar-sublink#', $html), 'One All Expenses entry in the sidebar.');
        $this->assertStringContainsString('Expense Categories', $html);
        $this->assertStringNotContainsString('Quick Actions', $html, 'An accountant books nothing, so the board offers no shortcuts.');
    }

    public function test_the_expenses_entry_does_not_ride_on_the_accounts_gate(): void
    {
        Role::firstOrCreate(['name' => 'expensewatch', 'guard_name' => 'web'])
            ->syncPermissions(['view-dashboard', 'view-expenses']);

        $user = User::create([
            'name' => 'Watcher Only',
            'email' => 'watcher'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ])->assignRole('expensewatch');

        $this->assertFalse($user->fresh()->can('view-accounts'));

        $html = $this->html($user);
        $this->assertStringContainsString('id="expensesGroup"', $html);
        $this->assertMatchesRegularExpression('#<a href="[^"]+/expenses" class="sidebar-sublink[^"]*">#', $html);
        $this->assertStringNotContainsString('id="accountsGroup"', $html);
    }

    public function test_the_expenses_entry_lights_up_while_an_expense_is_on_screen(): void
    {
        $html = $this->html($this->user('accountant', 'Grace Naigaga'), '/expenses');

        $this->assertMatchesRegularExpression('#<div id="expensesGroup" class="">#', $html, 'The group is open while an expense is on screen.');
        $this->assertMatchesRegularExpression('#<a href="[^"]+/expenses" class="sidebar-sublink[^"]*active[^"]*">#', $html);
    }

    public function test_the_heads_an_expense_falls_under_sit_with_the_spending_book(): void
    {
        $admin = $this->html($this->user('admin', 'Admin One'));
        $this->assertMatchesRegularExpression('#<a href="[^"]+/expenses/categories" class="sidebar-sublink[^"]*">#', $admin);

        // An accountant reads the spending book without owning the list, so the same link opens
        // the read-only page: seeing the categories is behind view-expenses, changing them stays
        // behind manage-expense-categories.
        $accountant = $this->html($this->user('accountant', 'Grace Naigaga'));
        $this->assertMatchesRegularExpression('#<a href="[^"]+/expenses/categories" class="sidebar-sublink[^"]*">#', $accountant);
    }

    public function test_quick_actions_offer_a_role_only_the_things_it_may_do(): void
    {
        // The board keeps the five shortcuts a desk actually reaches for from this screen and drops
        // the rest: Expenses, Exchange Rates, Transactions and Add Customer are already in the
        // sidebar, so a tile for each of them was a second copy of the menu sitting on top of the
        // figures. The office and the lead agent see the whole book, so both corridors and both
        // withdrawal queues are theirs. A hand at one desk is only offered the send that starts at
        // its counter and the withdrawal queue at its own counter.
        $chips = ['Send to China', 'Send to Uganda', 'Pay out in China', 'Pay out in Uganda', 'Record Exchange'];

        $superadmin = $this->quickActions($this->html($this->user('superadmin', 'Super Admin')));
        foreach ($chips as $label) {
            $this->assertStringContainsString($label, $superadmin, $label.' is a Super Admin shortcut.');
        }
        $this->assertStringNotContainsString('Approve hand-ins', $superadmin, 'That queue belongs to the lead agent.');
        foreach (['Expenses', 'Exchange Rates', 'Add Customer', 'Transactions'] as $label) {
            $this->assertStringNotContainsString($label, $superadmin, $label.' lives in the sidebar, not on the board.');
        }

        // A desk agent books one way and withdraws at their own counter. Handing cash in is not a
        // tile: it is the per-transfer modal on the Transactions page.
        $agent = $this->quickActions($this->html($this->user('agent', 'Amina Nabukenya')));
        $this->assertStringContainsString('Send to China', $agent);
        $this->assertStringNotContainsString('Send to Uganda', $agent, 'Uganda is where this agent already stands.');
        $this->assertStringContainsString('Pay out in Uganda', $agent, 'A desk in Uganda withdraws where its own receivers collect.');
        $this->assertStringNotContainsString('Pay out in China', $agent, 'The China queue stays on the China Desk page, not as a second tile.');
        $this->assertStringNotContainsString('Hand Cash In', $agent);
        $this->assertStringNotContainsString('Approve hand-ins', $agent);
        $this->assertStringNotContainsString('Record Exchange', $agent, 'Changing money is beyond an agent.');

        // The lead agent books for either desk and answers for both queues.
        $lead = $this->quickActions($this->html($this->user('super-agent', 'Amina Lead')));
        foreach ($chips as $label) {
            $this->assertStringContainsString($label, $lead, $label.' is a Super Agent shortcut.');
        }
        $this->assertStringContainsString('/china#payouts', $lead);
        $this->assertStringContainsString('/uganda#payouts', $lead);
        $this->assertStringNotContainsString('Hand Cash In', $lead, 'The lead agent receives the hand-in.');

        // The mirror holds at the other desk: a hand in China sends to Uganda and is never offered
        // the road that would pay a receiver back in China.
        $china = $this->quickActions($this->html($this->user('agent', 'Li Fang', 'CN')));
        $this->assertStringContainsString('Send to Uganda', $china);
        $this->assertStringNotContainsString('Send to China', $china);
        $this->assertStringContainsString('Pay out in China', $china);
        $this->assertStringNotContainsString('Pay out in Uganda', $china, 'The Uganda queue is not this desk\'s counter.');

        // The accountant checks the spending book and reports on it, but writes nothing, so the
        // board holds no shortcut for her at all.
        $this->assertStringNotContainsString('Quick Actions', $this->html($this->user('accountant', 'Grace Naigaga')));

        // Desk staff read the day and change nothing, so there is nothing for them to start.
        $this->assertStringNotContainsString('Quick Actions', $this->html($this->user('staff', 'Peter Mugisha')));
    }

    private function quickActions(string $html): string
    {
        $start = strpos($html, '>Quick Actions</h2>');
        $this->assertIsInt($start, 'The board offers no shortcuts for this role.');

        // The chips run to the heading of whatever section comes after them, and every following
        // section opens with its own heading.
        $end = strpos($html, '<h2 ', $start + 20);
        if ($end === false) {
            $end = strpos($html, '</body>', $start);
        }
        $this->assertIsInt($end);

        return substr($html, $start, $end - $start);
    }

    public function test_quick_actions_are_one_wrapping_row_of_chips(): void
    {
        foreach (['superadmin' => 'Super Admin', 'super-agent' => 'Amina Lead'] as $role => $name) {
            $block = $this->quickActions($this->html($this->user($role, $name)));

            // A grid of tall tiles is what pushed the figures off the page; the chips take the
            // height of their own labels and share one line until they run out of width.
            $this->assertStringContainsString('<div class="flex flex-wrap gap-2">', $block, $role.': the chips share one row.');
            $this->assertStringNotContainsString('grid-cols-1 gap-3 sm:grid-cols-2', $block);
            $this->assertStringNotContainsString('sm:col-span-2', $block, $role.': a chip keeps its size instead of filling the row.');
            $this->assertGreaterThan(3, substr_count($block, 'hover:border-green-300'), $role.': the full set of shortcuts is offered.');
        }

        $agent = $this->quickActions($this->html($this->user('agent', 'Amina Nabukenya')));
        $this->assertSame(2, substr_count($agent, 'hover:border-green-300'), 'One send and one withdrawal queue.');
    }

    public function test_the_board_offers_no_typed_hand_in_anymore(): void
    {
        $agent = $this->user('agent', 'Amina Nabukenya');

        $this->assertStringNotContainsString('Hand Cash In', $this->quickActions($this->html($agent)), 'The typed hand-in tile is gone from the board.');

        $this->user('super-agent', 'Amina Lead');

        $this->assertStringNotContainsString('Hand Cash In', $this->quickActions($this->html($agent->fresh())), 'An active lead agent does not bring the typed hand-in tile back.');
        $this->assertStringNotContainsString('hand-in-modal', $this->html($agent->fresh()));
        $this->assertStringNotContainsString('data-hand-in-open', $this->html($agent->fresh()));
    }

    public function test_the_dashboard_carries_no_hand_in_dialog_anymore(): void
    {
        $this->user('super-agent', 'Amina Lead');
        $agent = $this->user('agent', 'Amina Nabukenya');
        AccountLedger::record(Account::walletFor($agent, 'UGX'), 'adjustment_in', '500000.00', 'Float from the office');

        $board = $this->html($agent);

        $this->assertStringNotContainsString('data-hand-in-open', $board);
        $this->assertStringNotContainsString('hand-in-modal', $board);
        $this->assertStringNotContainsString('role="dialog"', $board);
        $this->assertStringNotContainsString('hand-in-amount', $board);
        $this->assertStringNotContainsString('Hand cash in to your super agent', $board);
        $this->assertStringNotContainsString('>Amina Lead</option>', $board, 'Nothing on the board offers a super agent dropdown anymore.');
    }

    public function test_a_lead_declining_from_the_board_still_gets_the_page_banner(): void
    {
        $agent = $this->user('agent', 'Amina Nabukenya');
        $peter = $this->user('super-agent', 'Peter Lead');
        $handover = CashHandover::request($agent, $peter, 'UGX', '100000');

        $declined = $this->actingAs($peter)->from(route('dashboard'))->followingRedirects()
            ->post(route('cash-handovers.decline', $handover), ['decision_note' => ''])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Say why you are sending this hand-in back.', $declined);
        $this->assertStringContainsString('bg-red-100 border border-red-400', $declined);
    }

    public function test_every_shortcut_a_role_is_handed_open_for_that_role(): void
    {
        $agent = $this->user('agent', 'Amina Nabukenya');

        foreach (['/transactions/create', '/expenses', '/expenses/create'] as $path) {
            $this->flushSession();
            $this->actingAs($agent)->get($path)->assertOk();
        }

        $this->flushSession();
        $this->actingAs($agent)->get('/exchanges/create')->assertForbidden();

        $lead = $this->user('super-agent', 'Amina Lead');

        foreach (['/transactions/create', '/exchanges/create', '/expenses', '/expenses/create', '/china'] as $path) {
            $this->flushSession();
            $this->actingAs($lead)->get($path)->assertOk();
        }
    }

    public function test_a_hand_is_offered_its_own_road_and_the_whole_book_both(): void
    {
        $uganda = $this->quickActions($this->html($this->user('agent', 'Amina Nabukenya')));

        // A hand at one counter only sends the way that counter takes cash in, and the label says
        // which. The corridor is named on the chip rather than left for the form to turn around.
        $this->assertSame(1, substr_count($uganda, 'Send to China'), 'One chip, not a second copy elsewhere on the board.');
        $this->assertStringNotContainsString('Send to Uganda', $uganda, 'A Uganda hand never pays into Uganda.');
        $this->assertStringNotContainsString('>Send<', $uganda, 'No chip sends money without saying which way.');

        $china = $this->quickActions($this->html($this->user('agent', 'Li Fang', 'CN')));

        $this->assertSame(1, substr_count($china, 'Send to Uganda'));
        $this->assertStringNotContainsString('Send to China', $china, 'A China hand never pays into China.');

        // The lead agent keys in for either desk, which the booking form already lets them do, so
        // both roads are on the board rather than one and a detour through the form.
        $lead = $this->quickActions($this->html($this->user('super-agent', 'Amina Lead')));

        $this->assertSame(1, substr_count($lead, 'Send to China'));
        $this->assertSame(1, substr_count($lead, 'Send to Uganda'));
        $this->assertSame(1, substr_count($lead, 'Record Exchange'));
        $this->assertStringNotContainsString('New Transaction', $lead);
    }

    public function test_the_sidebar_offers_no_send_money_link(): void
    {
        // Bookings start from the Send chips on the board, so the sidebar carries no second door
        // for it. The chips spell the corridor out, which is why their labels are still found.
        foreach (['agent' => 'Amina Nabukenya', 'super-agent' => 'Amina Lead'] as $role => $name) {
            $html = $this->html($this->user($role, $name));

            // The sidebar links carry their text inside a span, so the pin reads only that shape;
            // the empty-state link on the board renders as an anchor and stays.
            $this->assertSame(0, substr_count($html, '>Send Money</span>'), $role.': no sidebar entry for Send Money.');
            $this->assertStringContainsString('Send to China', $this->quickActions($html));
        }
    }

    public function test_the_book_pages_offer_no_new_transaction_button(): void
    {
        // Bookings start at the Send Money wizard or a dashboard tile. The book pages used to
        // carry a New Transaction button that fed the same cycle a second way, so it is gone:
        // whoever reads the transactions or receipts page starts a booking through the one door.
        $agent = $this->user('agent', 'Amina Nabukenya');

        $this->assertTrue($agent->can('create-transaction'));

        foreach (['/transactions', '/receipts'] as $path) {
            $this->assertStringNotContainsString('New Transaction', $this->html($agent, $path), $path);
        }
    }

    public function test_each_sending_shortcut_opens_its_own_corridor(): void
    {
        $uganda = $this->html($this->user('agent', 'Amina Nabukenya'));

        $this->assertMatchesRegularExpression('#transactions/create\?destination=CN"#', $uganda);
        $this->assertStringNotContainsString('destination=UG', $uganda);

        $china = $this->html($this->user('agent', 'Li Fang', 'CN'));

        $this->assertMatchesRegularExpression('#transactions/create\?destination=UG"#', $china);
        $this->assertStringNotContainsString('destination=CN', $china);

        $lead = $this->html($this->user('super-agent', 'Amina Lead'));

        $this->assertMatchesRegularExpression('#transactions/create\?destination=CN"#', $lead);
        $this->assertMatchesRegularExpression('#transactions/create\?destination=UG"#', $lead);
    }

    public function test_the_lead_agent_is_handed_the_withdrawal_and_approval_queues(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $agent = $this->user('agent', 'Amina Nabukenya');
        $chinaDesk = $this->user('agent', 'Li Fang', 'CN');

        // Money that came from Uganda, crossed to the desk in China and is still owed to its
        // receiver: this is the withdrawal queue the shortcut has to open.
        $waiting = Transaction::withoutEvents(fn () => Transaction::create([
            'receipt_number' => 'PS-20261021-0001',
            'sender_name' => 'Sender One',
            'sender_contact' => '',
            'receiver_name' => 'Receiver One',
            'receiver_contact' => '',
            'destination' => 'CN',
            'payout_currency' => 'RMB',
            'currency' => 'USD',
            'amount' => 100,
            'exchange_rate' => 6.75,
            'rmb_amount' => 675,
            'total_usd_equivalent' => 100,
            'charge_rate' => 0,
            'charge_amount' => 0,
            'charge_ugx' => 0,
            'status' => 'completed',
            'fx_profit_ugx' => 0,
            'fx_profit_usd' => 0,
            'usd_rmb_profit_ugx' => 0,
            'usd_rmb_profit_cny' => 0,
            'rate_buy_ugx' => 3750,
            'created_by' => $agent->id,
        ]));

        $waiting->forceFill(['settled_at' => now(), 'settled_to' => $chinaDesk->id])->save();

        CashHandover::request($agent, $lead, 'UGX', '100000');

        $board = $this->html($lead);

        $this->assertStringContainsString('Pay out in China', $board);
        $this->assertStringContainsString('Pay out in Uganda', $board);
        $this->assertStringContainsString('1 receiver waiting to be paid', $board);
        $this->assertStringContainsString('/china#payouts', $board);
        $this->assertStringContainsString('Approve hand-ins', $board);
        $this->assertStringContainsString('1 request waiting for you', $board);
        $this->assertStringContainsString(route('cash-handovers.index'), $board);
        $this->assertStringNotContainsString('id="cash-handed-to-you"', $board);

        // The queue the chip names, read on the same rule that counted it.
        $desk = $this->html($lead, '/china');

        $this->assertStringContainsString('id="payouts"', $desk);
        $this->assertStringContainsString('Pay receivers in China', $desk);
        $this->assertStringContainsString('PS-20261021-0001', $desk);
        $this->assertStringContainsString('Mark as paid', $desk);

        // A hand in Uganda holds the payout pen, so the board shows the withdrawal queue at its
        // own counter, and the China queue it may also clear is one click away on the China desk.
        $this->assertStringContainsString('Pay out in Uganda', $this->html($agent));
        $this->assertStringContainsString('Pay receivers in China', $this->html($agent, '/china'));
    }

    public function test_the_figures_lead_and_the_charts_and_prices_close_the_board(): void
    {
        $html = $this->html($this->user('agent', 'Amina Nabukenya'));

        // A desk opens the board to read what moved, so the Overview is the first thing on the
        // page; then what it can do, then the cash it is running on. The pictures and the counter
        // price are the last thing before the recent work, because neither is a figure to check.
        $overview = strpos($html, '>Overview</h2>');
        $actions = strpos($html, '>Quick Actions</h2>');
        $wallets = strpos($html, 'Wallet</div>');
        $rates = strpos($html, 'id="dashboard-rate-heading"');
        $recent = strpos($html, '>Recent Transactions</h2>');

        $this->assertIsInt($overview);
        $this->assertIsInt($actions);
        $this->assertIsInt($wallets);
        $this->assertIsInt($rates);
        $this->assertIsInt($recent);
        $this->assertLessThan($actions, $overview, 'The figures come before the shortcuts.');
        $this->assertLessThan($wallets, $actions, 'The shortcuts come before the till.');
        $this->assertLessThan($rates, $wallets, 'The till comes before the charts and the price.');
        $this->assertLessThan($recent, $rates, 'The recent work closes the board.');
    }
}

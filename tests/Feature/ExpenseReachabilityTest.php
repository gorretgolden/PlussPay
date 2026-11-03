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
        // Accounts or Settings. The card on the page is the second route to the same book.
        $this->assertStringContainsString('onclick="toggleExpensesGroup()"', $html);
        $this->assertSame(1, preg_match_all('#<a href="[^"]+/expenses" class="sidebar-sublink#', $html), 'One All Expenses entry in the sidebar.');
        $this->assertStringContainsString('Expense Categories', $html);
        $this->assertStringContainsString('>Expenses</span>', $html, 'The card on the board still offers the book.');
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
        // The office and the lead agent see the whole book, so both corridors and both withdrawal
        // queues are theirs. A hand at one desk is only offered the send that starts at its counter
        // and the withdrawal queue at its own counter, while the payout pen still clears either
        // queue from the desk page, wherever its own desk stands.
        $labels = ['Send Money to China', 'Send Money to Uganda', 'Withdraw Money in China', 'Withdraw Money in Uganda', 'Record Exchange', 'Expenses', 'Exchange Rates', 'Add Customer', 'Transactions'];

        $lead = $this->user('super-agent', 'Amina Lead');

        $superadmin = $this->html($this->user('superadmin', 'Super Admin'));
        foreach ($labels as $label) {
            $this->assertStringContainsString('>'.$label.'</span>', $superadmin, $label.' is a Super Admin shortcut.');
        }
        $this->assertStringContainsString('Every transfer in the book', $superadmin);
        $this->assertStringContainsString('Pay receivers of money sent from Uganda', $superadmin);
        $this->assertStringContainsString('Pay receivers of money sent from China', $superadmin);
        $this->assertStringNotContainsString('>Approve Cash Hand-In</span>', $superadmin, 'That queue belongs to the lead agent.');

        // A desk agent books transfers and logs what they spent out of the cash in hand, so the
        // spending tile hands him the pen. The counter, the prices and the customer list stay with
        // the office and the super agent. Each check reads the shortcut card itself, because the
        // sidebar carries the same words further up the page. Handing cash in is not a tile: it is
        // the per-transfer modal on the Transactions page.
        $agentPage = $this->html($this->user('agent', 'Amina Nabukenya'));
        $agent = $this->quickActions($agentPage);
        $this->assertStringContainsString('>Send Money to China</span>', $agent);
        $this->assertStringNotContainsString('>Send Money to Uganda</span>', $agent, 'Uganda is where this agent already stands.');
        $this->assertStringContainsString('>Record Expense</span>', $agent, 'The desk that paid the cash out is the desk that records it.');
        $this->assertStringContainsString('Log what you spent today', $agent);
        $this->assertStringNotContainsString('>Hand Cash In</span>', $agent, 'The typed hand-in tile is gone; the modal on the Transactions page is the way in.');
        $this->assertStringContainsString('>Withdraw Money in Uganda</span>', $agent, 'A desk in Uganda withdraws where its own receivers collect.');
        $this->assertStringNotContainsString('>Withdraw Money in China</span>', $agent, 'The China queue stays on the China Desk page, not as a second tile.');
        foreach (['Record Exchange', 'Exchange Rates', 'Add Customer', 'Transactions'] as $label) {
            $this->assertStringNotContainsString('>'.$label.'</span>', $agent, $label.' is beyond an agent.');
        }

        // The lead agent adds the counter and the whole book to the same board: he is the one who
        // changes money, sends for every agent and answers for the withdrawal queue. He receives
        // the cash rather than handing it up, so the hand-in card is not his, but approving what
        // the agents hand over is.
        $lead = $this->quickActions($this->html($lead));
        $this->assertStringNotContainsString('>Hand Cash In</span>', $lead, 'The lead agent receives the hand-in.');
        foreach (['Send Money to China', 'Send Money to Uganda', 'Withdraw Money in China', 'Withdraw Money in Uganda', 'Approve Cash Hand-In', 'Record Exchange', 'Record Expense', 'Transactions'] as $label) {
            $this->assertStringContainsString('>'.$label.'</span>', $lead, $label.' is a Super Agent shortcut.');
        }
        $this->assertStringContainsString('Every transfer in the book', $lead);
        $this->assertStringContainsString('/china#payouts', $lead);
        $this->assertStringContainsString('/uganda#payouts', $lead);
        $this->assertStringContainsString(route('cash-handovers.index'), $lead);
        foreach (['Exchange Rates', 'Add Customer'] as $label) {
            $this->assertStringNotContainsString('>'.$label.'</span>', $lead, $label.' is beyond a Super Agent.');
        }

        // The mirror holds at the other desk: a hand in China sends to Uganda and is never offered
        // the road that would pay a receiver back in China. Their own withdrawal queue is the one
        // at their counter, and the Uganda queue is not their tile.
        $china = $this->html($this->user('agent', 'Li Fang', 'CN'));
        $this->assertStringContainsString('>Send Money to Uganda</span>', $china);
        $this->assertStringContainsString('Cash in China, paid in Uganda', $china);
        $this->assertStringContainsString('>Withdraw Money in China</span>', $china);
        $this->assertStringNotContainsString('>Withdraw Money in Uganda</span>', $china, 'The Uganda queue is not this desk\'s counter.');
        $this->assertStringNotContainsString('>Send Money to China</span>', $china);
        $this->assertStringNotContainsString('>Hand Cash In</span>', $china, 'Cash in China is paid to receivers, not handed up to a super agent.');

        // The accountant checks the spending book and reports on it, so the book is one click away
        // while the pen that writes it is not offered.
        $accountant = $this->html($this->user('accountant', 'Grace Naigaga'));
        $this->assertStringContainsString('>Expenses</span>', $accountant);
        $this->assertStringNotContainsString('>Record Expense</span>', $accountant, 'Recording spending is the desk that paid it out.');
        $this->assertStringNotContainsString('>Send Money to China</span>', $accountant, 'Booking a transfer is beyond an accountant.');
        $this->assertStringNotContainsString('>Send Money to Uganda</span>', $accountant, 'Nor is the other corridor.');
        $this->assertStringNotContainsString('>Withdraw Money in China</span>', $accountant, 'Nor does an accountant clear the China queue.');
        $this->assertStringNotContainsString('>Withdraw Money in Uganda</span>', $accountant, 'Nor the Uganda one.');

        // Desk staff read the day and change nothing, so there is nothing for them to start.
        $staff = $this->html($this->user('staff', 'Peter Mugisha'));
        $this->assertStringNotContainsString('Quick Actions', $staff);
    }

    private function quickActions(string $html): string
    {
        $start = strpos($html, '>Quick Actions</h2>');
        $this->assertIsInt($start);

        // The shortcuts sit above the rate card now, so the block is bounded by the heading of
        // whatever section comes after it rather than by the wallets below. Every following
        // section opens with its own heading, and the last one on the board is the recent work
        // table.
        $end = strpos($html, '<h2 ', $start + 20);
        if ($end === false) {
            $end = strpos($html, '</body>', $start);
        }
        $this->assertIsInt($end);

        return substr($html, $start, $end - $start);
    }

    public function test_quick_actions_sit_three_to_a_row_and_never_stretch(): void
    {
        foreach (['super-agent' => 'Amina Lead', 'agent' => 'Amina Nabukenya'] as $role => $name) {
            $block = $this->quickActions($this->html($this->user($role, $name)));
            $tiles = substr_count($block, 'hover:border-green-300');

            $this->assertSame(0, substr_count($block, 'col-span'), $role.': a tile keeps its size instead of filling the row.');

            // One grid for every board: two tiles to a row from the tablet up and three from the
            // wide board up, whichever number of tiles the role is actually offered. The third
            // column waits for 1280 because at 1024 the sidebar still stands and each tile is left
            // 216px, which is narrower than the longest label needs to stay on one line.
            $this->assertStringContainsString(
                'grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4',
                $block,
                $role.': '.$tiles.' shortcuts.'
            );

            // Every tile is built the same way, so nothing is dressed up as more important.
            $this->assertSame($tiles, substr_count($block, 'rounded-lg border border-gray-200 bg-white p-3'));
            // A label that cannot fit gives up its tail instead of running over the chevron, and
            // carries its full name on the tile for a pointer to read.
            $this->assertSame($tiles, substr_count($block, 'whitespace-nowrap overflow-hidden text-ellipsis'));
        }

        $agent = $this->quickActions($this->html($this->user('agent', 'Amina Nabukenya')));
        $this->assertSame(3, substr_count($agent, 'hover:border-green-300'), 'The send card, the withdrawal and the spending book.');
        $this->assertSame(3, substr_count($agent, 'w-10 h-10 shrink-0 rounded-full'), 'Each tile carries one 40px mark.');
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
        $uganda = $this->html($this->user('agent', 'Amina Nabukenya'));

        // The button that used to sit on the Recent Transactions header booked the same thing
        // under a second name, so the card's entries are now the only ones of those labels. Sidebar
        // links keep their text outside a span, so each count below is the card alone. A hand at
        // one counter only sends the way that counter takes cash in, and the label says which.
        $this->assertSame(1, substr_count($uganda, '>Send Money to China</span>'));
        $this->assertSame(0, substr_count($uganda, '>Send Money to Uganda</span>'), 'A Uganda hand never pays into Uganda.');
        $this->assertSame(0, substr_count($uganda, '>Send Money</span>'), 'No card sends money without saying which way.');

        $china = $this->html($this->user('agent', 'Li Fang', 'CN'));

        $this->assertSame(1, substr_count($china, '>Send Money to Uganda</span>'));
        $this->assertSame(0, substr_count($china, '>Send Money to China</span>'), 'A China hand never pays into China.');

        // The lead agent keys in for either desk, which the booking form already lets them do, so
        // both roads are on the board rather than one and a detour through the form.
        $lead = $this->html($this->user('super-agent', 'Amina Lead'));

        $this->assertSame(1, substr_count($lead, '>Send Money to China</span>'));
        $this->assertSame(1, substr_count($lead, '>Send Money to Uganda</span>'));
        $this->assertSame(0, substr_count($lead, '>Send Money</span>'), 'No card sends money without saying which way.');
        $this->assertSame(1, substr_count($lead, '>Record Exchange</span>'));
        $this->assertStringNotContainsString('New Transaction', $lead);
    }

    public function test_the_sidebar_offers_no_send_money_link(): void
    {
        // Bookings start from the Send Money tiles on the board, so the sidebar carries no second
        // door for it. The tiles spell the corridor out, which is why their labels are still found.
        foreach (['agent' => 'Amina Nabukenya', 'super-agent' => 'Amina Lead'] as $role => $name) {
            $html = $this->html($this->user($role, $name));

            // The sidebar links carry their text inside a span, so the pin reads only that one
            // shape; the empty-state link renders as an anchor and stays.
            $this->assertSame(0, substr_count($html, '>Send Money</span>'), $role.': no sidebar entry for Send Money.');
            $this->assertStringContainsString('>Send Money to China</span>', $html);
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

        $this->assertStringContainsString('>Withdraw Money in China</span>', $board);
        $this->assertStringContainsString('>Withdraw Money in Uganda</span>', $board);
        $this->assertStringContainsString('1 receiver waiting to be paid', $board);
        $this->assertStringContainsString('/china#payouts', $board);
        $this->assertStringContainsString('>Approve Cash Hand-In</span>', $board);
        $this->assertStringContainsString('1 request waiting for you', $board);
        $this->assertStringContainsString(route('cash-handovers.index'), $board);
        $this->assertStringNotContainsString('id="cash-handed-to-you"', $board);

        // The queue the shortcut names, read on the same rule that counted it.
        $desk = $this->html($lead, '/china');

        $this->assertStringContainsString('id="payouts"', $desk);
        $this->assertStringContainsString('Pay receivers in China', $desk);
        $this->assertStringContainsString('PS-20261021-0001', $desk);
        $this->assertStringContainsString('Mark as paid', $desk);

        // A hand in Uganda holds the payout pen, so the board shows the withdrawal queue at its
        // own counter, and the China queue it may also clear is one click away on the China desk.
        $this->assertStringContainsString('>Withdraw Money in Uganda</span>', $this->html($agent));
        $this->assertStringContainsString('Pay receivers in China', $this->html($agent, '/china'));
    }

    public function test_the_till_and_its_price_come_first_and_the_shortcuts_under_both(): void
    {
        $html = $this->html($this->user('agent', 'Amina Nabukenya'));

        // A desk opens on what it holds, reads the counter price those figures are kept at, and
        // only then reaches for the work it can do.
        $wallets = strpos($html, 'Wallet</div>');
        $rates = strpos($html, 'id="dashboard-rate-heading"');
        $actions = strpos($html, '>Quick Actions</h2>');

        $this->assertIsInt($wallets);
        $this->assertIsInt($rates);
        $this->assertIsInt($actions);
        $this->assertLessThan($rates, $wallets, 'The wallet cards open the board above the rate card.');
        $this->assertLessThan($actions, $rates, 'The rate card sits above the shortcuts.');
    }
}

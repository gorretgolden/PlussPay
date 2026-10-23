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

        // A top-level entry, not a sub-link that stays hidden until another group is opened. The
        // card on the page is the second route to the same book, which is what she asked for.
        $this->assertSame(1, preg_match_all('#<a href="[^"]+/expenses" class="sidebar-link#', $html), 'One Expenses entry in the sidebar.');
        $this->assertSame(2, substr_count($html, '>Expenses</span>'), 'The sidebar entry and the card on the board, once each.');
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
        $this->assertMatchesRegularExpression('#<a href="[^"]+/expenses" class="sidebar-link[^"]*">#', $html);
        $this->assertStringNotContainsString('id="accountsGroup"', $html);
    }

    public function test_the_expenses_entry_lights_up_while_an_expense_is_on_screen(): void
    {
        $html = $this->html($this->user('accountant', 'Grace Naigaga'), '/expenses');

        $this->assertMatchesRegularExpression('#<a href="[^"]+/expenses" class="sidebar-link[^"]*active[^"]*">#', $html);
    }

    public function test_the_heads_an_expense_falls_under_sit_with_the_other_settings(): void
    {
        $admin = $this->html($this->user('admin', 'Admin One'));
        $this->assertMatchesRegularExpression('#<a href="[^"]+/expenses/categories" class="sidebar-sublink[^"]*">#', $admin);

        // An accountant reads the spending book but neither owns the heads nor writes in the book.
        $accountant = $this->html($this->user('accountant', 'Grace Naigaga'));
        $this->assertStringNotContainsString('/expenses/categories', $accountant);
    }

    public function test_quick_actions_offer_a_role_only_the_things_it_may_do(): void
    {
        // The office and the lead agent see the whole book, so both corridors and the withdrawal
        // queue are theirs. A hand at one desk is only offered the road that starts at its counter.
        $labels = ['Send Money to China', 'Send Money to Uganda', 'Withdraw Money in China', 'Record Exchange', 'Expenses', 'Exchange Rates', 'Add Customer', 'Transactions'];

        $lead = $this->user('super-agent', 'Amina Lead');

        $superadmin = $this->html($this->user('superadmin', 'Super Admin'));
        foreach ($labels as $label) {
            $this->assertStringContainsString('>'.$label.'</span>', $superadmin, $label.' is a Super Admin shortcut.');
        }
        $this->assertStringContainsString('Every transfer in the book', $superadmin);
        $this->assertStringContainsString('Pay receivers of money sent from Uganda', $superadmin);
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
        $this->assertStringNotContainsString('>Withdraw Money in China</span>', $agent, 'The China queue is not a Uganda agent\'s to clear.');
        foreach (['Record Exchange', 'Exchange Rates', 'Add Customer', 'Transactions'] as $label) {
            $this->assertStringNotContainsString('>'.$label.'</span>', $agent, $label.' is beyond an agent.');
        }

        // The lead agent adds the counter and the whole book to the same board: he is the one who
        // changes money, sends for every agent and answers for the withdrawal queue. He receives
        // the cash rather than handing it up, so the hand-in card is not his, but approving what
        // the agents hand over is.
        $lead = $this->quickActions($this->html($lead));
        $this->assertStringNotContainsString('>Hand Cash In</span>', $lead, 'The lead agent receives the hand-in.');
        foreach (['Send Money to China', 'Send Money to Uganda', 'Withdraw Money in China', 'Approve Cash Hand-In', 'Record Exchange', 'Record Expense', 'Transactions'] as $label) {
            $this->assertStringContainsString('>'.$label.'</span>', $lead, $label.' is a Super Agent shortcut.');
        }
        $this->assertStringContainsString('Every transfer in the book', $lead);
        $this->assertStringContainsString('/china#payouts', $lead);
        $this->assertStringContainsString('href="#cash-handed-to-you"', $lead);
        foreach (['Exchange Rates', 'Add Customer'] as $label) {
            $this->assertStringNotContainsString('>'.$label.'</span>', $lead, $label.' is beyond a Super Agent.');
        }

        // The mirror holds at the other desk: a hand in China sends to Uganda and is never offered
        // the road that would pay a receiver back in China. The withdrawal queue is theirs because
        // they are the ones handing the money over.
        $china = $this->html($this->user('agent', 'Li Fang', 'CN'));
        $this->assertStringContainsString('>Send Money to Uganda</span>', $china);
        $this->assertStringContainsString('Cash in China, paid in Uganda', $china);
        $this->assertStringContainsString('>Withdraw Money in China</span>', $china);
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

        // Desk staff read the day and change nothing, so there is nothing for them to start.
        $staff = $this->html($this->user('staff', 'Peter Mugisha'));
        $this->assertStringNotContainsString('Quick Actions', $staff);
    }

    private function quickActions(string $html): string
    {
        $start = strpos($html, '>Quick Actions</h2>');
        $this->assertIsInt($start);

        // The shortcuts now sit under the wallet cards, so the block is bounded by whatever card
        // comes after it rather than by the wallets above. Every following section opens with its
        // own heading, and the last one on the board is the recent work table.
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

            $this->assertSame(0, substr_count($block, 'sm:col-span-2'), $role.': a tile keeps its size instead of filling the row.');

            // The rule the layout follows: exactly three shortcuts get one row from the small
            // breakpoint up, anything else waits for the wide board before it gets a third column.
            $this->assertStringContainsString(
                $tiles === 3 ? 'grid-cols-1 gap-3 sm:grid-cols-3' : 'grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3',
                $block,
                $role.': '.$tiles.' shortcuts.'
            );
        }

        $agent = $this->quickActions($this->html($this->user('agent', 'Amina Nabukenya')));
        $this->assertSame(2, substr_count($agent, 'hover:border-green-300'), 'The send card and the spending book.');
        $this->assertStringContainsString('sm:col-span-1', $agent, 'The primary tile is pinned to one column.');
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
        $this->assertStringContainsString('1 receiver waiting to be paid', $board);
        $this->assertStringContainsString('/china#payouts', $board);
        $this->assertStringContainsString('>Approve Cash Hand-In</span>', $board);
        $this->assertStringContainsString('1 request waiting for you', $board);
        $this->assertStringContainsString('href="#cash-handed-to-you"', $board);
        $this->assertStringContainsString('id="cash-handed-to-you"', $board);

        // The queue the shortcut names, read on the same rule that counted it.
        $desk = $this->html($lead, '/china');

        $this->assertStringContainsString('id="payouts"', $desk);
        $this->assertStringContainsString('Pay receivers in China', $desk);
        $this->assertStringContainsString('PS-20261021-0001', $desk);
        $this->assertStringContainsString('Mark as paid', $desk);

        $this->assertStringNotContainsString('>Withdraw Money in China</span>', $this->html($agent), 'A hand in Uganda does not clear the China queue.');
    }

    public function test_the_balances_sit_ahead_of_the_shortcuts(): void
    {
        $html = $this->html($this->user('agent', 'Amina Nabukenya'));

        // A desk counts its till first and then decides what to do with what is in it, so the
        // wallet cards are the top card and the shortcuts come under them.
        $actions = strpos($html, '>Quick Actions</h2>');
        $wallets = strpos($html, 'Wallet</div>');

        $this->assertIsInt($actions);
        $this->assertIsInt($wallets);
        $this->assertLessThan($actions, $wallets, 'The wallet cards render above Quick Actions.');
    }
}

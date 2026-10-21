<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashHandover;
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
        // Every account here sits at the Uganda desk, so the send shortcut is the road that starts
        // here: cash in Uganda, paid in China. A Uganda hand is never offered a payment back into
        // the country the customer is already standing in.
        $labels = ['Send Money to China', 'Record Exchange', 'Expenses', 'Exchange Rates', 'Add Customer', 'Transactions'];

        // The lead agent is made first, because an ordinary agent is offered the hand-in shortcut
        // only while there is an active super agent to receive the request.
        $lead = $this->user('super-agent', 'Amina Lead');

        $superadmin = $this->html($this->user('superadmin', 'Super Admin'));
        foreach ($labels as $label) {
            $this->assertStringContainsString('>'.$label.'</span>', $superadmin, $label.' is a Super Admin shortcut.');
        }
        $this->assertStringContainsString('Every transfer in the book', $superadmin);
        $this->assertStringNotContainsString('Send Money to Uganda', $superadmin);

        // A desk agent books transfers and logs what they spent out of the cash in hand. The
        // counter, the prices and the customer list stay with the office and the super agent.
        $agent = $this->html($this->user('agent', 'Amina Nabukenya'));
        $this->assertStringContainsString('>Send Money to China</span>', $agent);
        $this->assertStringNotContainsString('>Send Money to Uganda</span>', $agent, 'Uganda is where this agent already stands.');
        $this->assertStringContainsString('>Expenses</span>', $agent, 'Their own page of the book, where the pen sits above their entries.');
        $this->assertStringContainsString('Record spending, read your entries', $agent);
        $this->assertStringContainsString('>Hand Cash In</span>', $agent, 'An agent hands the desk cash up to the lead agent.');
        $this->assertStringContainsString('Send cash to your super agent', $agent);
        $this->assertStringContainsString('/wallet#hand-in', $agent, 'The card opens the form itself.');
        foreach (['Record Exchange', 'Record Expense', 'Exchange Rates', 'Add Customer'] as $label) {
            $this->assertStringNotContainsString('>'.$label.'</span>', $agent, $label.' is beyond an agent.');
        }

        // The lead agent adds the counter and the whole book to the same board: he is the one who
        // changes money and the one who answers for every agent's transfers. He receives the cash
        // rather than handing it up, so the hand-in card is not his.
        $lead = $this->html($lead);
        $this->assertStringNotContainsString('>Hand Cash In</span>', $lead, 'The lead agent receives the hand-in.');
        foreach (['Send Money to China', 'Record Exchange', 'Expenses', 'Transactions'] as $label) {
            $this->assertStringContainsString('>'.$label.'</span>', $lead, $label.' is a Super Agent shortcut.');
        }
        $this->assertStringContainsString('Every transfer in the book', $lead);
        $this->assertStringNotContainsString('>Send Money to Uganda</span>', $lead);
        foreach (['Exchange Rates', 'Add Customer'] as $label) {
            $this->assertStringNotContainsString('>'.$label.'</span>', $lead, $label.' is beyond a Super Agent.');
        }

        // The mirror holds at the other desk: a hand in China sends to Uganda and is never offered
        // the road that would pay a receiver back in China.
        $china = $this->html($this->user('agent', 'Li Fang', 'CN'));
        $this->assertStringContainsString('>Send Money to Uganda</span>', $china);
        $this->assertStringContainsString('Cash in China, paid in Uganda', $china);
        $this->assertStringNotContainsString('>Send Money to China</span>', $china);

        // The accountant checks the spending book and reports on it, so the book is one click away
        // while the pen that writes it is not offered.
        $accountant = $this->html($this->user('accountant', 'Grace Naigaga'));
        $this->assertStringContainsString('>Expenses</span>', $accountant);
        $this->assertStringNotContainsString('>Record Expense</span>', $accountant, 'Recording spending is the desk that paid it out.');
        $this->assertStringNotContainsString('>Send Money to China</span>', $accountant, 'Booking a transfer is beyond an accountant.');
        $this->assertStringNotContainsString('>Send Money to Uganda</span>', $accountant, 'Nor is the other corridor.');

        // Desk staff read the day and change nothing, so there is nothing for them to start.
        $staff = $this->html($this->user('staff', 'Peter Mugisha'));
        $this->assertStringNotContainsString('Quick Actions', $staff);
    }

    private function quickActions(string $html): string
    {
        $start = strpos($html, '>Quick Actions</h2>');
        $this->assertIsInt($start);

        $end = strpos($html, 'Cash Handed To You', $start);
        if ($end === false) {
            $end = strpos($html, 'Wallet</div>', $start);
        }
        $this->assertIsInt($end);

        return substr($html, $start, $end - $start);
    }

    public function test_quick_actions_sit_two_to_a_row_and_fill_the_last_row(): void
    {
        $lead = $this->quickActions($this->html($this->user('super-agent', 'Amina Lead')));

        $this->assertStringContainsString('grid-cols-1 sm:grid-cols-2', $lead);
        $this->assertStringNotContainsString('lg:grid-cols-3', $lead);
        $this->assertSame(4, substr_count($lead, 'hover:border-green-300'), 'The send card, the counter, the spending book and the whole book.');
        $this->assertStringNotContainsString('sm:col-span-2', $lead, 'Four cards fill two rows exactly.');

        $agent = $this->quickActions($this->html($this->user('agent', 'Amina Nabukenya')));

        $this->assertSame(3, substr_count($agent, 'hover:border-green-300'), 'The send card, the hand-in card and the spending book.');
        $this->assertSame(1, substr_count($agent, 'sm:col-span-2'), 'The odd last card fills its row instead of leaving a gap.');
    }

    public function test_the_hand_in_card_waits_for_an_active_lead_agent(): void
    {
        $agent = $this->user('agent', 'Amina Nabukenya');

        $this->assertStringNotContainsString('Hand Cash In', $this->quickActions($this->html($agent)), 'No lead agent, so nowhere to hand the cash.');

        $lead = $this->user('super-agent', 'Amina Lead');
        $lead->update(['is_active' => false]);

        $this->assertStringNotContainsString('Hand Cash In', $this->quickActions($this->html($agent->fresh())), 'An inactive lead agent does not count.');

        $lead->update(['is_active' => true]);

        $actions = $this->quickActions($this->html($agent->fresh()));
        $this->assertStringContainsString('>Hand Cash In</span>', $actions);
        $this->assertStringContainsString('/wallet#hand-in', $actions);

        // The wallet page still carries the same form as the fallback the card names.
        $this->assertStringContainsString('id="hand-in"', $this->html($agent->fresh(), '/wallet'));
    }

    public function test_the_hand_in_shortcut_opens_the_form_on_the_board_itself(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $agent = $this->user('agent', 'Amina Nabukenya');
        AccountLedger::record(Account::walletFor($agent, 'UGX'), 'adjustment_in', '500000.00', 'Float from the office');

        $board = $this->html($agent);

        // The shortcut is an opener for a card on this page, not a second link to the wallet.
        $this->assertStringContainsString('data-hand-in-open', $board);
        $this->assertStringContainsString('id="hand-in-card"', $board);
        $this->assertStringContainsString('action="'.route('cash-handovers.store').'"', $board);
        $this->assertStringContainsString('>Hand cash in to your super agent</h2>', $board);
        $this->assertStringContainsString('>Amina Lead</option>', $board);
        $this->assertStringContainsString('UGX (UGX 500,000 in hand)', $board);
        $this->assertStringContainsString('value="500000"', $board);

        // A figure over the drawer comes back to the same open card, with the box marked and a
        // summary that links to it. The page-level banner above the board is not repeated.
        $failed = $this->actingAs($agent)->from(route('dashboard'))->followingRedirects()
            ->post(route('cash-handovers.store'), [
                'super_agent_id' => $lead->id,
                'currency' => 'USD',
                'amount' => '9999',
            ])->assertOk()->getContent();

        $this->assertStringContainsString('<div data-error-summary', $failed);
        $this->assertStringContainsString('href="#hand-in-amount"', $failed);
        $this->assertMatchesRegularExpression('#id="hand-in-amount"[^>]*aria-invalid="true"#', $failed);
        $this->assertMatchesRegularExpression('#id="hand-in-amount"[^>]*field-invalid#', $failed);
        $this->assertStringNotContainsString('bg-red-100 border border-red-400', $failed);

        // A request sent from the board comes straight back to the board.
        $this->actingAs($agent)->from(route('dashboard'))->post(route('cash-handovers.store'), [
            'super_agent_id' => $lead->id,
            'currency' => 'UGX',
            'amount' => '500000',
            'note' => "Day's collections",
        ])->assertRedirect(route('dashboard'))->assertSessionHas('success');

        $this->assertDatabaseHas('cash_handovers', [
            'agent_id' => $agent->id,
            'super_agent_id' => $lead->id,
            'currency' => 'UGX',
            'amount' => '500000.00',
            'status' => CashHandover::PENDING,
        ]);

        // The hand-in summary replaces the page banner only for its own fields. A lead agent
        // declining a hand-in from this same board still gets the page banner for that message.
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

        foreach (['/transactions/create', '/exchanges/create', '/expenses', '/expenses/create'] as $path) {
            $this->flushSession();
            $this->actingAs($lead)->get($path)->assertOk();
        }
    }

    public function test_each_desk_is_offered_only_the_road_that_starts_at_its_counter(): void
    {
        $uganda = $this->html($this->user('super-agent', 'Amina Lead'));

        // The button that used to sit on the Recent Transactions header booked the same thing
        // under a second name, so the card's entries are now the only ones of those labels. Sidebar
        // links keep their text outside a span, so each count below is the card alone. One send card
        // says which way, and it is the only one: the road that starts where the desk stands.
        $this->assertSame(1, substr_count($uganda, '>Send Money to China</span>'));
        $this->assertSame(0, substr_count($uganda, '>Send Money to Uganda</span>'), 'A Uganda hand never pays into Uganda.');
        $this->assertSame(0, substr_count($uganda, '>Send Money</span>'), 'No card sends money without saying which way.');
        $this->assertSame(1, substr_count($uganda, '>Record Exchange</span>'));
        $this->assertStringNotContainsString('New Transaction', $uganda);

        $china = $this->html($this->user('super-agent', 'Li Lead', 'CN'));

        $this->assertSame(1, substr_count($china, '>Send Money to Uganda</span>'));
        $this->assertSame(0, substr_count($china, '>Send Money to China</span>'), 'A China hand never pays into China.');
    }

    public function test_each_sending_shortcut_opens_its_own_corridor(): void
    {
        $uganda = $this->html($this->user('agent', 'Amina Nabukenya'));

        $this->assertMatchesRegularExpression('#transactions/create\?destination=CN"#', $uganda);
        $this->assertStringNotContainsString('destination=UG', $uganda);

        $china = $this->html($this->user('agent', 'Li Fang', 'CN'));

        $this->assertMatchesRegularExpression('#transactions/create\?destination=UG"#', $china);
        $this->assertStringNotContainsString('destination=CN', $china);
    }

    public function test_the_shortcuts_open_the_page_ahead_of_the_balances(): void
    {
        $html = $this->html($this->user('agent', 'Amina Nabukenya'));

        // The desk books first and reads the till afterwards, so the shortcuts are the top card.
        $actions = strpos($html, '>Quick Actions</h2>');
        $wallets = strpos($html, 'Wallet</div>');

        $this->assertIsInt($actions);
        $this->assertIsInt($wallets);
        $this->assertLessThan($wallets, $actions, 'Quick Actions renders above the wallet cards.');
    }
}

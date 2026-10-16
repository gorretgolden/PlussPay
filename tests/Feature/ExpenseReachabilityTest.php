<?php

namespace Tests\Feature;

use App\Models\User;
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

    private function user(string $role, string $name): User
    {
        $model = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $model->syncPermissions(PermissionCatalog::defaults()[$role] ?? []);

        $user = User::create([
            'name' => $name,
            'email' => strtolower($role).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
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
        $this->assertSame(1, substr_count($html, '>Expenses</span>'));
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

        // An accountant records against a head but does not own the list.
        $accountant = $this->html($this->user('accountant', 'Grace Naigaga'));
        $this->assertStringNotContainsString('/expenses/categories', $accountant);
    }

    public function test_quick_actions_offer_a_role_only_the_things_it_may_do(): void
    {
        $labels = ['Send Money', 'Record Exchange', 'Record Expense', 'Expenses', 'Exchange Rates', 'Add Customer'];

        $superadmin = $this->html($this->user('superadmin', 'Super Admin'));
        foreach ($labels as $label) {
            $this->assertStringContainsString('>'.$label.'</span>', $superadmin, $label.' is a Super Admin shortcut.');
        }

        // A desk agent books transfers, swaps notes and logs what they spent out of the cash in
        // hand. Prices and the customer list stay with the office.
        $agent = $this->html($this->user('agent', 'Amina Nabukenya'));
        $this->assertStringContainsString('>Send Money</span>', $agent);
        $this->assertStringContainsString('>Record Exchange</span>', $agent);
        $this->assertStringContainsString('>Record Expense</span>', $agent, 'An agent who spends the desk\'s cash records it themselves.');
        $this->assertStringContainsString('>Expenses</span>', $agent, 'Their own page of the book, reached from the same card.');
        foreach (['Exchange Rates', 'Add Customer'] as $label) {
            $this->assertStringNotContainsString('>'.$label.'</span>', $agent, $label.' is beyond an agent.');
        }

        // Desk staff read the day and print receipts, so there is nothing for them to start.
        $staff = $this->html($this->user('staff', 'Peter Mugisha'));
        $this->assertStringNotContainsString('Quick Actions', $staff);
    }

    public function test_every_shortcut_a_role_is_handed_open_for_that_role(): void
    {
        $agent = $this->user('agent', 'Amina Nabukenya');

        foreach (['/transactions/create', '/exchanges/create', '/expenses', '/expenses/create'] as $path) {
            $this->flushSession();
            $this->actingAs($agent)->get($path)->assertOk();
        }
    }

    public function test_send_money_is_offered_once_on_the_dashboard(): void
    {
        $html = $this->html($this->user('agent', 'Amina Nabukenya'));

        // The button that used to sit on the Recent Transactions header booked the same thing
        // under a second name, so the card's entry is now the only one of those labels. Sidebar
        // links keep their text outside a span, so each count below is the card alone.
        $this->assertSame(1, substr_count($html, '>Send Money</span>'));
        $this->assertSame(1, substr_count($html, '>Record Exchange</span>'));
        $this->assertStringNotContainsString('New Transaction', $html);
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

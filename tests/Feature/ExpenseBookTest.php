<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Expense;
use App\Models\User;
use App\Services\AccountLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What the business spent on itself. The point of these tests is the money actually leaving the
 * account that paid: an expense that does not move a cash book is a note, not a record.
 */
class ExpenseBookTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'accountant', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = $this->makeUser('admin');
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role).' Books',
            'email' => strtolower($role).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function fund(string $code, string $amount): Account
    {
        $account = Account::byCode($code);

        AccountLedger::record($account, 'opening_balance', $amount, 'Float for the desk');

        return $account;
    }

    private function expense(Account $account, array $overrides = []): Expense
    {
        return Expense::record(array_merge([
            'expense_date' => now()->toDateString(),
            'category' => 'Rent',
            'description' => 'Kampala office',
            'currency' => $account->currency,
            'amount' => (string) $account->balance(),
            'account_id' => $account->id,
            'paid_via' => 'Cash',
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    public function test_recording_an_expense_takes_the_money_out_of_the_shilling_account(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_date' => '2026-09-01',
            'category' => 'Rent',
            'description' => 'Kampala office, October',
            'amount' => '1200000',
            'account_id' => $till->id,
            'paid_via' => 'Mobile money',
        ])->assertSessionHasNoErrors()->assertRedirect(route('expenses.index'));

        $expense = Expense::latest('id')->firstOrFail();

        $this->assertSame('EXP-'.now()->format('Ymd').'-0001', $expense->reference);
        // The currency is whatever the chosen account holds, not a field to get wrong.
        $this->assertSame('UGX', $expense->currency);
        $this->assertSame('1200000.00', (string) $expense->amount);
        $this->assertSame('3800000.00', $till->balance());

        $line = AccountEntry::where('reference', $expense->reference)->firstOrFail();

        $this->assertSame('money_out', $line->type);
        $this->assertSame('out', $line->direction);
        $this->assertSame($till->id, $line->account_id);
        // Posted on the day the money left, not the day it was typed in.
        $this->assertSame('2026-09-01', $line->entry_date->toDateString());
        // The ledger line carries the head, the details, the reference and the method so it reads
        // the same as the expense book without opening a second screen.
        $this->assertStringContainsString('Rent: Kampala office, October', $line->description);
        $this->assertStringContainsString($expense->reference, $line->description);
        $this->assertStringContainsString('paid via mobile money', $line->description);

        // Re-running the posting must not take the money out twice.
        AccountLedger::postExpense($expense);
        $this->assertSame(1, AccountEntry::where('reference', $expense->reference)->count());
        $this->assertSame('3800000.00', $till->balance());
    }

    public function test_paying_from_the_china_book_spends_yuan(): void
    {
        $yuan = $this->fund(Account::RMB_CHINA, '20000.00');

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_date' => now()->toDateString(),
            'category' => 'Transport',
            'description' => 'Courier to the Guangzhou office',
            'amount' => '650',
            'account_id' => $yuan->id,
        ])->assertSessionHasNoErrors();

        $expense = Expense::latest('id')->firstOrFail();

        $this->assertSame('RMB', $expense->currency);
        $this->assertSame('19350.00', $yuan->balance());
        $this->assertSame('0.00', Account::byCode(Account::UGX_UGANDA)->balance());

        $this->actingAs($this->admin)->get('/expenses')
            ->assertOk()
            ->assertSee('Spent in yuan')
            ->assertSee('¥650.00');
    }

    public function test_cancelling_an_expense_puts_the_money_back_and_keeps_both_lines(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');
        $expense = $this->expense($till, ['amount' => '1200000.00']);

        $this->assertSame('3800000.00', $till->balance());

        $this->actingAs($this->admin)->post('/expenses/'.$expense->id.'/void', [
            'reason' => 'The landlord refunded part of it',
        ])->assertSessionHasNoErrors();

        $this->assertSame('void', $expense->fresh()->status);
        $this->assertSame('5000000.00', $till->fresh()->balance());

        // Nothing is deleted: the payment and the refund both stay on the trail.
        $lines = AccountEntry::where('reference', $expense->reference)->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('out', $lines[0]->direction);
        $this->assertSame('in', $lines[1]->direction);
        $this->assertStringContainsString('Voided expense '.$expense->reference, $lines[1]->description);
        $this->assertStringContainsString('The landlord refunded part of it', $lines[1]->description);

        $this->actingAs($this->admin)->get('/expenses')
            ->assertOk()
            ->assertSee('Cancelled')
            ->assertSee('+1 cancelled');
    }

    public function test_cancelling_needs_a_reason_and_cannot_be_done_twice(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');
        $expense = $this->expense($till, ['amount' => '500000.00']);

        $this->actingAs($this->admin)->post('/expenses/'.$expense->id.'/void', [])
            ->assertSessionHasErrors('reason');

        $this->assertSame('active', $expense->fresh()->status);
        $this->assertSame('4500000.00', $till->fresh()->balance());

        $expense->void('Entered in error');

        $this->actingAs($this->admin)->post('/expenses/'.$expense->id.'/void', ['reason' => 'Again'])
            ->assertSessionHasErrors('reason');

        // The guard in the ledger means even a direct call cannot push the money back twice.
        AccountLedger::reverseExpense($expense->fresh(), 'Force of habit');
        $this->assertSame('5000000.00', $till->fresh()->balance());
        $this->assertSame(2, AccountEntry::where('reference', $expense->reference)->count());
    }

    public function test_the_amount_and_what_it_was_spent_on_are_required(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_date' => now()->toDateString(),
            'category' => 'Supplies',
            'description' => '',
            'amount' => '0',
            'account_id' => $till->id,
        ])->assertSessionHasErrors(['description', 'amount']);

        $this->assertSame(0, Expense::count());
        $this->assertSame(1, AccountEntry::count());
        $this->assertSame('5000000.00', $till->balance());
    }

    public function test_the_book_lists_entries_and_totals_them_by_currency(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');
        $this->expense($till, ['category' => 'Rent', 'amount' => '1200000.00']);
        $this->expense($till, ['category' => 'Salaries', 'description' => 'Driver salary', 'amount' => '450000.00']);

        $this->actingAs($this->admin)->get('/expenses')
            ->assertOk()
            ->assertSee('Expenses')
            ->assertSee('Rent')
            ->assertSee('Driver salary')
            ->assertSee('Spent in shillings')
            ->assertSee('UGX 1,650,000')
            ->assertSee('Record Expense');

        $this->actingAs($this->admin)->get('/expenses?category=Salaries')
            ->assertOk()
            ->assertSee('Driver salary')
            ->assertDontSee('Kampala office');

        $this->actingAs($this->admin)->get('/export/expenses')->assertOk();
        $this->actingAs($this->admin)->get('/export/expenses?category=Rent')->assertOk();
    }

    public function test_an_expense_shows_up_on_the_money_movements_page(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');
        $expense = $this->expense($till, ['amount' => '1200000.00']);

        $this->actingAs($this->admin)->get('/accounts/movements?search='.$expense->reference)
            ->assertOk()
            ->assertSee('Money Movements')
            ->assertSee($expense->reference)
            ->assertSee('UGX Cash (Uganda)')
            ->assertSee('UGX 1,200,000');

        $this->actingAs($this->admin)->get('/export/movements?search='.$expense->reference)->assertOk();
    }

    public function test_the_expense_book_is_for_the_people_keeping_the_books(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');
        $expense = $this->expense($till, ['amount' => '1200000.00']);

        $this->actingAs($this->makeUser('accountant'))->get('/expenses')->assertOk();
        $this->actingAs($this->makeUser('accountant'))->get('/expenses/create')->assertOk();

        // A desk agent books transfers and changes money, but does not spend the business's own cash.
        $this->actingAs($this->makeUser('agent'))->get('/expenses')->assertForbidden();
        $this->actingAs($this->makeUser('agent'))->post('/expenses/'.$expense->id.'/void', ['reason' => 'Nothing'])
            ->assertForbidden();
        $this->actingAs($this->makeUser('staff'))->get('/expenses')->assertForbidden();

        $this->assertSame('active', $expense->fresh()->status);
    }
}

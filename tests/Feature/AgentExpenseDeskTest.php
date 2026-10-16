<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\AccountLedger;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * An agent pays for transport, airtime and mobile money charges out of the cash sitting in their
 * hand, and the office should not have to type those in for them. Two things have to hold for that
 * to be safe: the money leaves the agent's own wallet and never the office till, and their page of
 * the book is the rows they entered. Both are checked here from the outside, through the same
 * routes the agent uses.
 */
class AgentExpenseDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::names() as $name) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (array_keys(PermissionCatalog::defaults()) as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])
                ->syncPermissions(PermissionCatalog::defaults()[$role]);
        }
    }

    private function user(string $role, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $role)).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** The cash an agent is holding, in shillings. */
    private function wallet(User $agent, string $currency = 'UGX'): Account
    {
        $wallet = Account::walletFor($agent, $currency);

        AccountLedger::record($wallet, 'opening_balance', '900000.00', 'Cash collected on deliveries');

        return $wallet;
    }

    private function fund(string $code, string $amount): Account
    {
        $till = Account::byCode($code);

        AccountLedger::record($till, 'opening_balance', $amount, 'Office float');

        return $till;
    }

    private function headId(): int
    {
        return ExpenseCategory::where('name', 'Transport')->value('id')
            ?? ExpenseCategory::orderBy('id')->value('id');
    }

    private function typed(User $agent, Account $from, string $amount = '45000.00'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($agent)->post('/expenses', [
            'category_id' => $this->headId(),
            'description' => 'Boda to the Mbale customer',
            'amount' => $amount,
            'account_id' => $from->id,
            'expense_date' => now()->toDateString(),
            'paid_via' => 'Mobile money',
        ]);
    }

    public function test_an_agent_is_offered_the_expense_desk_at_all(): void
    {
        $agent = $this->user('agent', 'Amina Nabukenya');

        $this->assertTrue($agent->can('view-expenses'), 'The door to their own page of the book.');
        $this->assertTrue($agent->can('create-expense'));
        $this->assertFalse($agent->can('view-all-expenses'), 'Reading the office entries stays with the office.');
        $this->assertFalse($agent->can('void-expense'), 'Cancelling a record is not theirs to do.');
        $this->assertFalse($agent->can('export-expenses'));

        $this->flushSession();
        $this->actingAs($agent)->get('/expenses/create')->assertOk();
    }

    public function test_the_only_account_an_agent_can_pay_from_is_the_wallet_in_their_hand(): void
    {
        $agent = $this->user('agent', 'Amina Nabukenya');
        $wallet = $this->wallet($agent);
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->flushSession();
        $html = $this->actingAs($agent)->get('/expenses/create')->assertOk()->getContent();

        $this->assertStringContainsString($wallet->name, $html);
        $this->assertStringNotContainsString('(UGX) (UGX)', $html, 'A wallet is named after its currency once.');
        $this->assertStringNotContainsString('UGX Cash (Uganda)', $html, 'The office till is not on an agent\'s list.');
        $this->assertStringNotContainsString('Dollar Account (Uganda)', $html);
    }

    public function test_recording_takes_the_money_out_of_the_agents_wallet_and_not_the_office_till(): void
    {
        $agent = $this->user('agent', 'Amina Nabukenya');
        $wallet = $this->wallet($agent);
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->typed($agent, $wallet)->assertSessionHasNoErrors();

        $expense = Expense::latest('id')->firstOrFail();

        $this->assertSame($agent->id, (int) $expense->created_by);
        $this->assertSame($wallet->id, (int) $expense->account_id);
        $this->assertSame('45000.00', (string) $expense->amount);
        $this->assertSame(855000.0, (float) $wallet->fresh()->balance());
        $this->assertSame(5000000.0, (float) $till->fresh()->balance(), 'The office cash book is untouched.');
    }

    public function test_an_agent_cannot_push_an_expense_onto_the_office_till(): void
    {
        $agent = $this->user('agent', 'Amina Nabukenya');
        $this->wallet($agent);
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->typed($agent, $till)->assertSessionHasErrors('account_id');

        $this->assertSame(0, Expense::count());
        $this->assertSame(5000000.0, (float) $till->fresh()->balance());
    }

    public function test_an_agents_page_of_the_book_holds_only_what_they_recorded(): void
    {
        $amina = $this->user('agent', 'Amina Nabukenya');
        $peter = $this->user('agent', 'Peter Okello');
        $office = $this->user('admin', 'Grace Naigaga');

        $this->typed($amina, $this->wallet($amina))->assertSessionHasNoErrors();
        $hers = Expense::latest('id')->firstOrFail();

        $this->flushSession();
        $this->typed($peter, $this->wallet($peter))->assertSessionHasNoErrors();
        $theirs = Expense::latest('id')->firstOrFail();

        $officeEntry = $this->expenseBy($office, $this->fund(Account::UGX_UGANDA, '5000000.00'));

        $this->flushSession();
        $own = $this->actingAs($amina)->get('/expenses')->assertOk()->getContent();

        $this->assertStringContainsString($hers->reference, $own);
        $this->assertStringNotContainsString($theirs->reference, $own, 'Another desk\'s spending is not on this page.');
        $this->assertStringNotContainsString($officeEntry->reference, $own);
        $this->assertStringNotContainsString('>Entered By</th>', $own, 'A column of one name twice tells the reader nothing.');
        $this->assertStringNotContainsString('name="created_by"', $own);
        $this->assertStringNotContainsString('Export Excel', $own);

        $this->flushSession();
        $every = $this->actingAs($office)->get('/expenses')->assertOk()->getContent();

        foreach ([$hers, $theirs, $officeEntry] as $entry) {
            $this->assertStringContainsString($entry->reference, $every);
        }

        $this->assertStringContainsString('>Entered By</th>', $every);
        $this->assertStringContainsString('UGX Cash (Uganda)', $every);
    }

    private function expenseBy(User $who, Account $from): Expense
    {
        $this->flushSession();
        $this->actingAs($who)->post('/expenses', [
            'category_id' => $this->headId(),
            'description' => 'Rent for the office',
            'amount' => '200000.00',
            'account_id' => $from->id,
            'expense_date' => now()->toDateString(),
            'paid_via' => 'Cash',
        ])->assertSessionHasNoErrors();

        return Expense::latest('id')->firstOrFail();
    }

    public function test_an_agent_opens_their_own_entry_and_is_turned_away_from_every_other_one(): void
    {
        $amina = $this->user('agent', 'Amina Nabukenya');
        $peter = $this->user('agent', 'Peter Okello');

        $this->typed($amina, $this->wallet($amina))->assertSessionHasNoErrors();
        $hers = Expense::latest('id')->firstOrFail();

        $this->flushSession();
        $this->typed($peter, $this->wallet($peter))->assertSessionHasNoErrors();
        $theirs = Expense::latest('id')->firstOrFail();

        $this->flushSession();
        $this->actingAs($amina)->get('/expenses/'.$hers->id)->assertOk();

        $this->flushSession();
        $this->actingAs($amina)->get('/expenses/'.$theirs->id)->assertForbidden();

        // The account page behind the ledger lines is a different door, so the name is printed as
        // text rather than linked into a page that would refuse them.
        $this->flushSession();
        $html = $this->actingAs($amina)->get('/expenses/'.$hers->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('/accounts/'.$hers->account_id, $html);
    }

    public function test_an_agent_cannot_cancel_or_export_even_their_own_entry(): void
    {
        $amina = $this->user('agent', 'Amina Nabukenya');
        $wallet = $this->wallet($amina);
        $this->typed($amina, $wallet)->assertSessionHasNoErrors();
        $expense = Expense::latest('id')->firstOrFail();

        $this->flushSession();
        $this->actingAs($amina)->post('/expenses/'.$expense->id.'/void', ['reason' => 'Typed twice'])
            ->assertForbidden();

        $this->assertSame('active', $expense->fresh()->status);
        // Still the reduced figure: a refused cancellation must not have put the money back.
        $this->assertSame(855000.0, (float) $wallet->fresh()->balance());

        $this->flushSession();
        $this->actingAs($amina)->get('/export/expenses')->assertForbidden();
    }

    public function test_the_office_still_pays_its_own_spending_out_of_its_own_accounts(): void
    {
        $office = $this->user('admin', 'Grace Naigaga');
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->assertTrue($office->can('view-all-expenses'));

        $entry = $this->expenseBy($office, $till);

        $this->assertSame($till->id, (int) $entry->account_id);
        $this->assertSame(4800000.0, (float) $till->fresh()->balance());

        $this->flushSession();
        $html = $this->actingAs($office)->get('/expenses/create')->assertOk()->getContent();
        $this->assertStringContainsString('UGX Cash (Uganda)', $html);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccountLedger;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The three things the expense desk was asked for. The drawer that pays has to say what it holds
 * before the figure is typed, because the currency of the amount is decided there and nowhere
 * else. A figure that cannot have been paid, either because it is under the smallest entry the
 * desk takes or because the account named does not hold it, is refused before the books are
 * touched. And the words of an entry that is already paid can be corrected by the person who
 * recorded it, while the money it moved stays exactly as it was posted.
 *
 * The desk that records spending is the lead agent now: she ruled on 2026-11-04 that the agent
 * role does no expenses at all, so the desk below is read through the lead.
 */
class ExpenseDeskCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::names() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (array_keys(PermissionCatalog::defaults()) as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])
                ->syncPermissions(PermissionCatalog::defaults()[$role]);
        }

        Setting::set('min_transaction_amount', '1000', 'float', 'transaction');
    }

    private function user(string $role, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $role)).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => 'UG',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function wallet(User $lead, string $amount = '900000.00'): Account
    {
        $wallet = Account::walletFor($lead, 'UGX');
        AccountLedger::record($wallet, 'opening_balance', $amount, 'Cash collected on deliveries');

        return $wallet;
    }

    private function headId(string $name = 'Transport'): int
    {
        return ExpenseCategory::where('name', $name)->value('id')
            ?? ExpenseCategory::orderBy('id')->value('id');
    }

    private function typed(User $author, Account $from, string $amount = '45000.00'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($author)->post('/expenses', [
            'category_id' => $this->headId(),
            'description' => 'Boda to the Mbale customer',
            'amount' => $amount,
            'account_id' => $from->id,
            'expense_date' => now()->toDateString(),
            'paid_via' => 'Mobile money',
        ]);
    }

    public function test_the_drawer_names_itself_with_the_money_it_holds_and_is_chosen_first(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $this->wallet($lead);

        $html = $this->actingAs($lead)->get('/expenses/create')->assertOk()->getContent();

        $this->assertStringContainsString('Amina Lead (UGX) (900,000)', $html,
            'The balance is read inside the choice itself, not on a line below it.');
        $this->assertStringNotContainsString('(UGX) (UGX', $html, 'One currency code, not two.');
        $this->assertLessThan(strpos($html, 'Amount paid'), strpos($html, 'Paid out of'),
            'The wallet is picked before the figure typed into it.');
    }

    public function test_the_method_select_opens_on_a_choice_rather_than_nothing_recorded(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $this->wallet($lead);

        $html = $this->actingAs($lead)->get('/expenses/create')->assertOk()->getContent();

        $this->assertStringNotContainsString('Not recorded', $html);
        $this->assertStringContainsString('Choose how it was paid', $html);
        $this->assertStringContainsString('Smallest entry this desk takes', $html);
    }

    public function test_an_amount_under_the_smallest_entry_the_desk_takes_is_refused(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $wallet = $this->wallet($lead);

        $this->typed($lead, $wallet, '500.00')->assertSessionHasErrors('amount');

        $this->assertStringContainsString('UGX 1,000', session('errors')->first('amount'));
        $this->assertSame(0, Expense::count());
        $this->assertSame(1, AccountEntry::count(), 'The line that put the cash in is the only one on the book.');
        $this->assertSame(900000.0, (float) $wallet->fresh()->balance());
    }

    public function test_nothing_is_recorded_for_free(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $wallet = $this->wallet($lead);

        $this->typed($lead, $wallet, '0')->assertSessionHasErrors('amount');

        $this->assertSame(0, Expense::count());
        $this->assertSame(900000.0, (float) $wallet->fresh()->balance());
    }

    public function test_an_expense_larger_than_the_wallet_holds_is_refused_with_the_figure_named(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $wallet = $this->wallet($lead);

        $this->typed($lead, $wallet, '950000.00')->assertSessionHasErrors('amount');

        $this->assertStringContainsString('holds UGX 900,000', session('errors')->first('amount'));
        $this->assertSame(0, Expense::count());
        $this->assertSame(900000.0, (float) $wallet->fresh()->balance(), 'A refused entry leaves the cash untouched.');
    }

    public function test_the_china_side_pays_out_of_its_float_before_uganda_settles_across(): void
    {
        $office = $this->user('admin', 'Grace Naigaga');
        $yuan = Account::byCode(Account::RMB_CHINA);

        $this->actingAs($office)->post('/expenses', [
            'category_id' => $this->headId('Salaries'),
            'description' => 'Guangzhou office salaries',
            'amount' => '50000.00',
            'account_id' => $yuan->id,
            'expense_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Expense::count());
        $this->assertSame(-50000.0, (float) $yuan->fresh()->balance(),
            'An empty account on that side is who is owed what, not a payment that never happened.');
    }

    public function test_the_edit_page_offers_every_word_and_no_figure(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $expense = $this->expense($lead);

        $html = $this->actingAs($lead)->get('/expenses/'.$expense->id.'/edit')->assertOk()->getContent();

        foreach (['name="category_id"', 'name="description"', 'name="expense_date"', 'name="paid_via"', 'name="notes"'] as $field) {
            $this->assertStringContainsString($field, $html, 'Every word of the entry is correctable.');
        }

        $this->assertStringNotContainsString('name="amount"', $html);
        $this->assertStringNotContainsString('name="account_id"', $html);
        $this->assertStringContainsString('UGX 45,000', $html, 'The figure that was paid is shown, not typed.');
        $this->assertStringContainsString('Edit Expense', $html);
        $this->assertStringNotContainsString('takes the amount straight out', $html,
            'The page no longer describes a payment being made.');
    }

    public function test_the_person_who_recorded_an_entry_fixes_its_words_and_the_money_stands(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $wallet = $this->wallet($lead);
        $this->typed($lead, $wallet)->assertSessionHasNoErrors();

        $entry = Expense::firstOrFail();
        $this->assertSame(855000.0, (float) $wallet->fresh()->balance());

        $this->flushSession();
        $this->actingAs($lead)->put('/expenses/'.$entry->id, [
            'category_id' => $this->headId('Airtime and internet'),
            'description' => 'Airtime for the customer in Mbale',
            'expense_date' => now()->subDay()->toDateString(),
            'paid_via' => 'Cash',
            'notes' => 'MTN 20k',
        ])->assertRedirect(route('expenses.show', $entry));

        $entry->refresh();

        $this->assertSame('Airtime and internet', $entry->category);
        $this->assertSame('Airtime for the customer in Mbale', $entry->description);
        $this->assertSame(now()->subDay()->toDateString(), $entry->expense_date->toDateString());
        $this->assertSame('45000.00', (string) $entry->amount, 'The figure that left is not editable here.');
        $this->assertSame($wallet->id, (int) $entry->account_id);
        $this->assertSame(855000.0, (float) $wallet->fresh()->balance());

        $line = AccountEntry::where('reference', $entry->reference)
            ->where('direction', 'out')
            ->first();

        $this->assertNotNull($line);
        $this->assertSame(now()->subDay()->toDateString(), $line->entry_date->toDateString());
        $this->assertStringContainsString('Airtime and internet: Airtime for the customer in Mbale', $line->description);
        $this->assertStringContainsString('paid via cash', $line->description);
        $this->assertStringNotContainsString('Mobile money', $line->description,
            'The book line and the entry cannot describe the same cash two ways.');
    }

    public function test_a_desk_cannot_rewrite_someone_elses_spending(): void
    {
        $amina = $this->user('super-agent', 'Amina Lead');
        $peter = $this->user('super-agent', 'Peter Lead');

        $mine = $this->expense($amina);
        $theirs = $this->expense($peter);

        $this->flushSession();
        $this->actingAs($amina)->get('/expenses/'.$theirs->id.'/edit')->assertForbidden();
        $this->actingAs($amina)->put('/expenses/'.$theirs->id, [
            'category_id' => $this->headId('Other'),
            'description' => 'Rewritten by somebody else',
            'expense_date' => now()->toDateString(),
        ])->assertForbidden();

        $this->actingAs($amina)->get('/expenses/'.$mine->id.'/edit')->assertOk();
        $this->assertSame('Boda to the Mbale customer', $theirs->fresh()->description);
    }

    public function test_a_cancelled_entry_is_closed_to_editing(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $expense = $this->expense($lead);
        $expense->void('Entered twice');

        $this->flushSession();
        $this->actingAs($lead)->get('/expenses/'.$expense->id.'/edit')->assertForbidden();
        $this->actingAs($lead)->put('/expenses/'.$expense->id, [
            'category_id' => $this->headId('Other'),
            'description' => 'Anything',
            'expense_date' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertSame('Boda to the Mbale customer', $expense->fresh()->description);
    }

    public function test_editing_is_not_a_way_to_write_in_a_book_one_cannot_write_in(): void
    {
        $accountant = $this->user('accountant', 'Faith Nansere');
        $expense = $this->expense($this->user('super-agent', 'Amina Lead'));

        $this->flushSession();
        $this->actingAs($accountant)->get('/expenses/'.$expense->id.'/edit')->assertForbidden();
        $this->actingAs($accountant)->put('/expenses/'.$expense->id, [
            'category_id' => $this->headId('Other'),
            'description' => 'Anything',
            'expense_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_the_office_fixes_any_desks_words(): void
    {
        $office = $this->user('admin', 'Grace Naigaga');
        $expense = $this->expense($this->user('super-agent', 'Amina Lead'));

        $this->actingAs($office)->get('/expenses/'.$expense->id.'/edit')->assertOk();
        $this->actingAs($office)->put('/expenses/'.$expense->id, [
            'category_id' => $this->headId('Other'),
            'description' => 'Corrected by the office',
            'expense_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('Corrected by the office', $expense->fresh()->description);
    }

    public function test_the_correction_is_offered_on_the_list_and_the_entry_page(): void
    {
        $amina = $this->user('super-agent', 'Amina Lead');
        $peter = $this->user('super-agent', 'Peter Lead');

        $mine = $this->expense($amina);
        $theirs = $this->expense($peter);

        $list = $this->actingAs($amina)->get('/expenses')->assertOk()->getContent();

        $this->assertStringContainsString('Edit the words', $list);
        $this->assertSame(1, substr_count($list, 'Edit the words'), 'Only the row they paid for.');

        $page = $this->actingAs($amina)->get('/expenses/'.$mine->id)->assertOk()->getContent();
        $this->assertStringContainsString('Edit the words', $page);

        $this->flushSession();
        $other = $this->actingAs($amina)->get('/expenses/'.$theirs->id);
        $this->assertTrue($other->status() === 403 || $other->status() === 404,
            'Another desk\'s entry is not even readable here.');
    }

    private function expense(User $author): Expense
    {
        $this->typed($author, $this->wallet($author))->assertSessionHasNoErrors();

        return Expense::latest('id')->firstOrFail();
    }
}

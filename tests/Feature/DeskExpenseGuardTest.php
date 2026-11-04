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
 * A desk pays for transport, airtime and mobile money charges out of the cash sitting in its
 * hand, and the office should not have to type those in for it. Two things have to hold for that
 * to be safe: the money leaves the desk's own wallet and never the office till, and their page of
 * the book is the rows they entered. Both are checked here from the outside, through the same
 * routes the desk uses.
 *
 * The desk that records spending is the lead agent now: she ruled on 2026-11-04 that the agent
 * role does no expenses at all, so every door of the module is closed to it and the guards below
 * are read through the lead instead.
 */
class DeskExpenseGuardTest extends TestCase
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

    /** The cash a desk is holding, in shillings. */
    private function wallet(User $desk, string $currency = 'UGX'): Account
    {
        $wallet = Account::walletFor($desk, $currency);

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

    private function typed(User $desk, Account $from, string $amount = '45000.00'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($desk)->post('/expenses', [
            'category_id' => $this->headId(),
            'description' => 'Boda to the Mbale customer',
            'amount' => $amount,
            'account_id' => $from->id,
            'expense_date' => now()->toDateString(),
            'paid_via' => 'Mobile money',
        ]);
    }

    public function test_the_lead_is_offered_the_expense_desk_and_an_agent_is_not(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $agent = $this->user('agent', 'Amina Nabukenya');

        $this->assertTrue($lead->can('view-expenses'), 'The door to the lead\'s own page of the book.');
        $this->assertTrue($lead->can('create-expense'));
        $this->assertFalse($lead->can('view-all-expenses'), 'Reading the office entries stays with the office.');
        $this->assertFalse($lead->can('void-expense'), 'Cancelling a record is not theirs to do.');
        $this->assertFalse($lead->can('export-expenses'));

        $this->assertFalse($agent->can('view-expenses'), 'She ruled the agent role does no expenses at all.');
        $this->assertFalse($agent->can('create-expense'));

        $this->flushSession();
        $this->actingAs($lead)->get('/expenses/create')->assertOk();

        $this->flushSession();
        $this->actingAs($agent)->get('/expenses/create')->assertForbidden();
        $this->actingAs($agent)->get('/expenses')->assertForbidden();
        $this->actingAs($agent)->post('/expenses', [])->assertForbidden();
    }

    public function test_the_only_account_a_desk_can_pay_from_is_the_wallet_in_their_hand(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $wallet = $this->wallet($lead);
        $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->flushSession();
        $html = $this->actingAs($lead)->get('/expenses/create')->assertOk()->getContent();

        $this->assertStringContainsString($wallet->name, $html);
        $this->assertStringNotContainsString('(UGX) (UGX)', $html, 'A wallet is named after its currency once.');
        $this->assertStringNotContainsString('UGX Cash (Uganda)', $html, 'The office till is not on a desk\'s list.');
        $this->assertStringNotContainsString('Dollar Account (Uganda)', $html);
    }

    public function test_recording_takes_the_money_out_of_the_desks_wallet_and_not_the_office_till(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $wallet = $this->wallet($lead);
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->typed($lead, $wallet)->assertSessionHasNoErrors();

        $expense = Expense::latest('id')->firstOrFail();

        $this->assertSame($lead->id, (int) $expense->created_by);
        $this->assertSame($wallet->id, (int) $expense->account_id);
        $this->assertSame('45000.00', (string) $expense->amount);
        $this->assertSame(855000.0, (float) $wallet->fresh()->balance());
        $this->assertSame(5000000.0, (float) $till->fresh()->balance(), 'The office cash book is untouched.');
    }

    public function test_a_desk_cannot_push_an_expense_onto_the_office_till(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $this->wallet($lead);
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $this->typed($lead, $till)->assertSessionHasErrors('account_id');

        $this->assertSame(0, Expense::count());
        $this->assertSame(5000000.0, (float) $till->fresh()->balance());
    }

    public function test_a_desks_page_of_the_book_holds_only_what_they_recorded(): void
    {
        $amina = $this->user('super-agent', 'Amina Lead');
        $peter = $this->user('super-agent', 'Peter Lead');
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

    public function test_a_desk_opens_their_own_entry_and_is_turned_away_from_every_other_one(): void
    {
        $amina = $this->user('super-agent', 'Amina Lead');
        $peter = $this->user('super-agent', 'Peter Lead');

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

    public function test_a_desk_cannot_cancel_or_export_even_their_own_entry(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $wallet = $this->wallet($lead);
        $this->typed($lead, $wallet)->assertSessionHasNoErrors();
        $expense = Expense::latest('id')->firstOrFail();

        $this->flushSession();
        $this->actingAs($lead)->post('/expenses/'.$expense->id.'/void', ['reason' => 'Typed twice'])
            ->assertForbidden();

        $this->assertSame('active', $expense->fresh()->status);
        // Still the reduced figure: a refused cancellation must not have put the money back.
        $this->assertSame(855000.0, (float) $wallet->fresh()->balance());

        $this->flushSession();
        $this->actingAs($lead)->get('/export/expenses')->assertForbidden();
    }

    /**
     * The export pen is a separate grant from reading the whole book, so a desk can be allowed to
     * download its own spending without being handed everyone else's. The file follows the screen:
     * own rows only until view-all-expenses opens the rest.
     */
    public function test_a_desk_allowed_to_export_downloads_only_its_own_expenses(): void
    {
        $lead = $this->user('super-agent', 'Amina Lead');
        $wallet = $this->wallet($lead);
        $this->typed($lead, $wallet)->assertSessionHasNoErrors();
        $hers = Expense::latest('id')->firstOrFail();

        $office = $this->user('admin', 'Grace Naigaga');
        $this->flushSession();
        $this->typed($office, $this->fund(Account::UGX_UGANDA, '5000000.00'))->assertSessionHasNoErrors();
        $officeEntry = Expense::latest('id')->firstOrFail();

        Role::findByName('super-agent', 'web')->givePermissionTo('export-expenses');

        $this->flushSession();
        $response = $this->actingAs($lead->fresh())->get('/export/expenses');
        $this->assertSame(200, $response->getStatusCode());

        $path = storage_path('app/expense-export-check.xlsx');
        file_put_contents($path, $response->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $cells = [];
        foreach ($sheet->getRowIterator() as $row) {
            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(false);
            foreach ($iterator as $cell) {
                $cells[] = (string) $cell->getValue();
            }
        }

        $this->assertContains($hers->reference, $cells);
        $this->assertNotContains($officeEntry->reference, $cells, 'The whole spending book needs view-all-expenses.');
    }

    /**
     * The cancel pen is separate from reading the whole book. A desk granted it must not be able
     * to put another desk's money back by typing the entry's id, and must still close its own.
     */
    public function test_a_desk_with_the_cancel_pen_cannot_cancel_another_desks_entry(): void
    {
        Role::firstOrCreate(['name' => 'bookkeeper', 'guard_name' => 'web'])
            ->syncPermissions(['view-expenses', 'create-expense', 'void-expense']);

        $amina = $this->user('bookkeeper', 'Amina Nabukenya');
        $owen = $this->user('bookkeeper', 'Owen Okello');

        $this->typed($amina, $this->wallet($amina))->assertSessionHasNoErrors();
        $hers = Expense::latest('id')->firstOrFail();

        $this->flushSession();
        $this->typed($owen, $this->wallet($owen, 'USD'))->assertSessionHasNoErrors();
        $his = Expense::latest('id')->firstOrFail();

        $this->flushSession();
        $this->actingAs($owen->fresh())
            ->post('/expenses/'.$hers->id.'/void', ['reason' => 'Typed id'])
            ->assertForbidden();

        $this->assertSame(Expense::ACTIVE, $hers->fresh()->status);

        $this->actingAs($owen->fresh())
            ->post('/expenses/'.$his->id.'/void', ['reason' => 'Paid twice'])
            ->assertRedirect('/expenses');

        $this->assertSame(Expense::VOID, $his->fresh()->status);
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

<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\CurrencyExchange;
use App\Models\Expense;
use App\Models\User;
use App\Services\AccountLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The rule these hold: cash only leaves a Uganda till if the till actually holds it, and the whole
 * booking comes back when it does not. A record that saves while its money movement is refused is
 * the failure being tested for, so each case checks the books as well as the balance.
 *
 * The China accounts are the deliberate exception. That side pays the receiver out of its own float
 * before Uganda settles, so a negative there is the honest position rather than a hole.
 */
class WalletOverdraftGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'accountant', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::create([
            'name' => 'Guard Admin',
            'email' => 'guard'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $this->admin->assignRole('admin');
    }

    private function fund(string $code, string $amount): Account
    {
        $account = Account::byCode($code);

        AccountLedger::record($account, 'opening_balance', $amount, 'Float for the desk');

        return $account;
    }

    public function test_an_expense_the_till_cannot_cover_is_refused_and_leaves_no_record(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '1000000.00');

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_date' => now()->toDateString(),
            'category' => 'Rent',
            'description' => 'Kampala office',
            'amount' => '1500000',
            'account_id' => $till->id,
            'paid_via' => 'Cash',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Expense::count());
        $this->assertSame(0, AccountEntry::where('type', 'money_out')->count());
        $this->assertSame('1000000.00', $till->fresh()->balance());
    }

    public function test_the_refusal_names_the_figure_the_till_actually_holds(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '1000000.00');

        $this->expectException(InsufficientFundsException::class);
        $this->expectExceptionMessage('UGX Cash (Uganda) holds UGX 1,000,000, which is not enough for this payment of UGX 1,000,001.');

        Expense::record([
            'expense_date' => now()->toDateString(),
            'category' => 'Rent',
            'description' => 'One shilling too much',
            'currency' => 'UGX',
            'amount' => '1000001.00',
            'account_id' => $till->id,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_an_expense_takes_exactly_what_was_entered_and_a_cancellation_puts_it_back(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '5000000.00');

        $expense = Expense::record([
            'expense_date' => now()->toDateString(),
            'category' => 'Transport',
            'description' => 'Entebbe run',
            'currency' => 'UGX',
            'amount' => '1200000.00',
            'account_id' => $till->id,
            'created_by' => $this->admin->id,
        ]);

        $this->assertSame('3800000.00', $till->fresh()->balance());

        $expense->void('Entered twice by mistake');

        $this->assertSame('5000000.00', $till->fresh()->balance());

        // Both movements stay on the trail: the payment out and the refund in, never an edit.
        $this->assertSame(1, AccountEntry::where('reference', $expense->reference)->where('direction', 'out')->count());
        $this->assertSame(1, AccountEntry::where('reference', $expense->reference)->where('direction', 'in')->count());
    }

    public function test_a_second_deal_cannot_spend_what_the_first_one_already_took(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '1000000.00');

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_date' => now()->toDateString(),
            'category' => 'Supplies',
            'description' => 'First round',
            'amount' => '600000',
            'account_id' => $till->id,
            'paid_via' => 'Cash',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post('/expenses', [
            'expense_date' => now()->toDateString(),
            'category' => 'Supplies',
            'description' => 'Second round',
            'amount' => '600000',
            'account_id' => $till->id,
            'paid_via' => 'Cash',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(1, Expense::count());
        $this->assertSame('400000.00', $till->fresh()->balance());
        $this->assertStringContainsString('UGX 400,000', session('errors')->first('amount'));
    }

    public function test_an_exchange_is_refused_when_the_account_handing_over_is_short_and_says_what_is_there(): void
    {
        $shillings = Account::byCode(Account::UGX_UGANDA);
        $dollars = $this->fund(Account::USD_UGANDA, '100.00');

        $this->actingAs($this->admin)->post('/exchanges', [
            'exchange_date' => now()->toDateString(),
            'person_name' => 'Li Qiang',
            'in_currency' => 'UGX',
            'in_amount' => '1300000',
            'in_account_id' => $shillings->id,
            'out_currency' => 'USD',
            'out_amount' => '500',
            'out_account_id' => $dollars->id,
        ])->assertSessionHasErrors('out_amount');

        // The deal, the money taken in and the money handed over all roll back together.
        $this->assertSame(0, CurrencyExchange::count());
        $this->assertSame('0.00', $shillings->fresh()->balance());
        $this->assertSame('100.00', $dollars->fresh()->balance());
        $this->assertStringContainsString('$100.00', session('errors')->first('out_amount'));
    }

    public function test_the_china_account_can_pay_out_more_than_it_holds_because_it_advances_the_float(): void
    {
        $yuan = Account::byCode(Account::RMB_CHINA);

        $this->actingAs($this->admin)->post('/accounts/entries', [
            'kind' => 'money_out',
            'account_id' => $yuan->id,
            'amount' => '6750',
            'description' => 'Paid Li Na on PS-20261016-0001',
        ])->assertSessionHasNoErrors();

        $this->assertSame('-6750.00', $yuan->fresh()->balance());
    }

    public function test_an_undo_goes_through_even_when_the_till_has_since_been_emptied(): void
    {
        $till = $this->fund(Account::UGX_UGANDA, '500000.00');

        AccountLedger::record($till, 'money_out', '500000.00', 'Every shilling spent');
        $this->assertSame('0.00', $till->fresh()->balance());

        // A correction is never refused for want of cash, or the mistake would stay on the books.
        AccountLedger::record($till, 'reversal', '500000.00', 'Handing back a cancelled transfer');

        $this->assertSame('-500000.00', $till->fresh()->balance());
    }
}

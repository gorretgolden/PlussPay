<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 6.75, 'buy_rate' => 6.75, 'sell_rate' => 6.75, 'is_active' => true]);
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3760, 'buy_rate' => 3750, 'sell_rate' => 3760, 'is_active' => true]);

        Setting::set('service_fee_ugx', '2000', 'float', 'transaction');
        Setting::set('service_fee_usd', '100', 'float', 'transaction');
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Books Admin',
            'email' => 'books'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole('admin');

        return $user;
    }

    private function ugxCash(): Account
    {
        return Account::byCode(Account::UGX_UGANDA);
    }

    public function test_the_four_accounts_exist_after_migrating(): void
    {
        $codes = array_map(fn (Account $a) => $a->code, Account::ordered());

        $this->assertSame(['UGX-UG', 'USD-UG', 'USD-CN', 'RMB-CN'], $codes);
    }

    public function test_an_approved_transfer_puts_the_money_in_the_uganda_till_once(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/transactions/create');
        $this->actingAs($admin)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Books Sender',
            'receiver_name' => 'Books Receiver',
            'currency' => 'UGX',
            'amount' => '3760000',
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::latest('id')->firstOrFail();

        // Booking alone moves nothing; the till waits for the approval.
        $this->assertSame('pending', $transaction->status);
        $this->assertSame('0.00', $this->ugxCash()->balance());

        $this->actingAs($admin)->patch("/transactions/{$transaction->id}/status", ['status' => 'completed'])
            ->assertSessionHasNoErrors();

        // UGX 3,760,000 handed over plus the UGX 20,000 fee.
        $this->assertSame('3780000.00', $this->ugxCash()->balance());
        $this->assertSame(1, AccountEntry::where('transaction_id', $transaction->id)->where('type', 'money_in')->count());

        // Re-running the posting must not double count.
        AccountLedger::postCompletedTransfer($transaction);
        $this->assertSame(1, AccountEntry::where('transaction_id', $transaction->id)->where('type', 'money_in')->count());
    }

    public function test_cancelling_a_completed_transfer_takes_the_money_back_out(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/transactions/create');
        $this->actingAs($admin)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Books Sender',
            'receiver_name' => 'Books Receiver',
            'currency' => 'UGX',
            'amount' => '3760000',
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::latest('id')->firstOrFail();
        $this->actingAs($admin)->patch("/transactions/{$transaction->id}/status", ['status' => 'completed']);

        $this->actingAs($admin)->patch("/transactions/{$transaction->id}/status", [
            'status' => 'cancelled',
            'reason' => 'Receiver details were wrong',
        ])->assertSessionHasNoErrors();

        $this->assertSame('0.00', $this->ugxCash()->balance());
        $this->assertSame(1, AccountEntry::where('transaction_id', $transaction->id)->where('type', 'reversal')->count());
    }

    public function test_sending_dollars_to_china_moves_the_same_amount_across_both_accounts(): void
    {
        $from = Account::byCode(Account::USD_UGANDA);
        $to = Account::byCode(Account::USD_CHINA);

        AccountLedger::record($from, 'money_in', '50000.00', 'Dollars bought for the China account');
        AccountLedger::transfer($from, $to, '40000.00', 'Dollars sent to China');

        $this->assertSame('10000.00', $from->balance());
        $this->assertSame('40000.00', $to->balance());
    }

    public function test_exchanging_records_both_legs_and_the_rate_it_was_done_at(): void
    {
        $dollars = Account::byCode(Account::USD_CHINA);
        $rmb = Account::byCode(Account::RMB_CHINA);

        AccountLedger::record($dollars, 'money_in', '4000.00', 'Dollars received from Uganda');
        AccountLedger::exchange($dollars, $rmb, '4000.00', '27240.00', 'Dollars exchanged into RMB in China');

        $this->assertSame('0.00', $dollars->balance());
        $this->assertSame('27240.00', $rmb->balance());

        $out = AccountEntry::where('account_id', $dollars->id)->where('type', 'exchange_out')->firstOrFail();
        $this->assertSame('6.810000', (string) $out->rate);
    }

    public function test_a_transfer_between_two_different_currencies_is_rejected(): void
    {
        $this->actingAs($this->admin())->post('/accounts/entries', [
            'kind' => 'transfer',
            'account_id' => Account::byCode(Account::USD_UGANDA)->id,
            'counterparty_id' => Account::byCode(Account::RMB_CHINA)->id,
            'amount' => '1000',
            'description' => 'Wrong kind',
        ])->assertSessionHasErrors('kind');

        $this->assertSame(0, AccountEntry::count());
    }

    public function test_daily_balances_carry_forward_day_by_day(): void
    {
        $account = $this->ugxCash();

        AccountLedger::record($account, 'money_in', '1000000.00', 'Cash received', '2026-10-01');
        AccountLedger::record($account, 'money_out', '250000.00', 'Dollars bought', '2026-10-02');

        $closing = $account->dailyClosing('2026-10-01', Account::dateRange('2026-10-01', '2026-10-03'));

        $this->assertSame('1000000.00', $closing['2026-10-01']['closing']);
        $this->assertSame('750000.00', $closing['2026-10-02']['closing']);
        $this->assertSame('750000.00', $closing['2026-10-03']['closing']);
    }

    public function test_agents_cannot_open_the_accounts(): void
    {
        $agent = User::create([
            'name' => 'Agent Books',
            'email' => 'agentbooks'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $agent->assignRole('agent');

        $this->actingAs($agent)->get('/accounts')->assertForbidden();
    }

    public function test_the_account_pages_render(): void
    {
        $admin = $this->admin();
        AccountLedger::record($this->ugxCash(), 'money_in', '500000.00', 'Opening float');

        $this->actingAs($admin)->get('/accounts')->assertOk()->assertSee('UGX Cash (Uganda)');
        $this->actingAs($admin)->get('/accounts/'.$this->ugxCash()->id)->assertOk()->assertSee('Opening');
        $this->actingAs($admin)->get('/accounts/entries/create')->assertOk();
        $this->actingAs($admin)->get('/accounts/daily-balances')->assertOk();
    }

    public function test_an_opening_balance_can_be_posted_from_the_entry_form(): void
    {
        $account = $this->ugxCash();

        $this->actingAs($this->admin())->post('/accounts/entries', [
            'kind' => 'opening_balance',
            'account_id' => $account->id,
            'amount' => '62889200',
            'entry_date' => '2026-05-01',
            'description' => 'Balance carried from the workbook',
        ])->assertSessionHasNoErrors();

        $this->assertSame('62889200.00', $account->fresh()->balance());
        $this->assertSame('opening_balance', AccountEntry::where('account_id', $account->id)->firstOrFail()->type);
    }
}
